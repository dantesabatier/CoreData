<?php

namespace Sabatier\CoreData;

use Override;
use PDOStatement;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\ProcessInfo;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_time;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\pluralize;

/** @internal */
class SQLFetchRequestContext extends SQLStoreRequestContext
{
    public FetchRequest $request {
        get {
            /** @var FetchRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    private(set) SQLEntity $sqlEntityForFetchRequest {
        get => $this->sqlEntityForFetchRequest ??= $this->sqlModel->entity($this->request->entity->name) ?? fatal_error("Entity \"{$this->request->entity->name}\" does not exists");
    }
    private(set) SQLStatement $fetchStatement {
        get => $this->fetchStatement ??= $this->generator->statement ?? fatal_error("Unable to generate SQL fetch statement");
    }
    private(set) float $duration = 0;
    private(set) PDOStatement $queryStatement {
        /** @noinspection PhpUnhandledExceptionInspection */
        get => $this->queryStatement ??= $this->connection->execute($this->fetchStatement);
    }

    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    /**
     * @param PDOStatement $statement
     * @return ArrayClass<Dictionary<mixed>>
     */
    private function fetchSnapshotsFromStatement(PDOStatement $statement): ArrayClass
    {
        /** @var Dictionary<Dictionary<mixed>> $byRootIDResult */
        $byRootIDResult = new Dictionary();
        /** @var Dictionary<Dictionary<mixed>> $snapshotIndex */
        $snapshotIndex = new Dictionary();
        /** @var Set<string> $nullPropertyPrefixes */
        $nullPropertyPrefixes = new Set();
        // Per to-many collection lookup index: ArrayClass->hash => [primaryKey value => element Dictionary].
        // Turns the linear $cursor->first()/contains() scans below into O(1) lookups while preserving their exact semantics (membership by primary key, latest-element fallback).
        /** @var array<int, array<string, Dictionary<mixed>>> $toManyIndex */
        $toManyIndex = [];
        do {
            /** @var EntityDescription $entityDescription */
            $entityDescription = $this->request->entity;
            /** @var array<string, mixed> $row */
            while ($row = $statement->fetch()) {
                $entityNameFromRow = $row[ManagedObjectEntityNameKey] ?? $entityDescription->name;
                /** @var SQLEntity $entity */
                $entity = $this->sqlModel->entitiesByName[$entityNameFromRow] ?? fatal_error("Entity \"$entityNameFromRow\" does not exist");
                $cursorEntity = $entity;
                $rootID = (string)($row[$entity->primaryKey->columnName] ?? "row_$byRootIDResult->count");
                /** @var Dictionary<mixed> $root */
                $root = $byRootIDResult[$rootID] ?? new Dictionary();
                foreach ($row as $pattern => $value) {
                    $value ??= Nil::nil();
                    $descriptor = ColumnDescriptor::forPattern($pattern);
                    $keyPathComponents = clone $descriptor->keyPathComponents;
                    $primaryKeyName = $cursorEntity->primaryKey->columnName;
                    $this->updateNullPropertyPrefixes($keyPathComponents, $primaryKeyName, $value, $nullPropertyPrefixes);
                    if ($nullPropertyPrefixes->contains(fn(string $prefix): bool => $descriptor->keyPath === $prefix || str_starts_with($descriptor->keyPath, "$prefix."))) {
                        continue;
                    }
                    $cursor = &$root;
                    $childrenID = $row["{$descriptor->basePathPrefix}_$primaryKeyName"] ?? null;
                    $parentID = $descriptor->hasParentPrefix ? ($row["{$descriptor->parentPathPrefix}_$primaryKeyName"] ?? null) : $rootID;
                    $isInsideCompositeAttribute = false;
                    foreach ($keyPathComponents as $key) {
                        $property = $cursorEntity->propertiesByName[$key] ?? $cursorEntity->compositeAttributeNameToSQLProperty[$key];
                        /** @var PropertyDescription|null $propertyDescription */
                        $propertyDescription = $property?->propertyDescription ?? $this->request->propertiesToFetch?->first(fn(string|PropertyDescription $p): bool => $p instanceof PropertyDescription ? $p->name === $key : $p === $key);
                        if ($propertyDescription instanceof CompositeAttributeDescription && $propertyDescription->name !== $key) {
                            $propertyDescription = $propertyDescription->elements->first(fn(AttributeDescription $element): bool => $element->name === $key) ?? $propertyDescription;
                        }
                        $isRelationship = $property instanceof SQLRelationship;
                        $isCompositeAttribute = ($property instanceof SQLAttribute && $property->isCompositeAttribute);
                        $isNavigational = ($isRelationship || $isCompositeAttribute);
                        if ($isNavigational) {
                            if ($cursor instanceof ArrayClass) {
                                $cursorKey = $cursor->hash;
                                $element = $toManyIndex[$cursorKey][(string)$parentID] ?? null;
                                if (!$element) {
                                    $element = $this->cloneFromIndex($snapshotIndex, $cursorEntity->entityDescription->name, $parentID);
                                    if ($element !== null) {
                                        $cursor->append($element);
                                        $toManyIndex[$cursorKey][(string)$parentID] = $element;
                                    } else {
                                        $element = new Dictionary([$primaryKeyName => $parentID]);
                                    }
                                }
                                $cursor = &$element;
                            }
                            if ($cursor instanceof Dictionary) {
                                if ($isRelationship) {
                                    /** @var SQLRelationship $relationship */
                                    $relationship = $property;
                                    if ($relationship instanceof SQLToOne) {
                                        $cursor[$key] ??= new Dictionary();
                                    } else {
                                        $cursor[$key] ??= new ArrayClass();
                                    }
                                    $cursor = &$cursor[$key];
                                    $cursorEntity = $relationship->destinationEntity;
                                    $primaryKeyName = $cursorEntity->primaryKey->columnName;
                                    $isInsideCompositeAttribute = false;
                                } elseif ($isCompositeAttribute) {
                                    /** @var SQLAttribute $attribute */
                                    $attribute = $property;
                                    $name = $attribute->name;
                                    $cursor[$name] ??= new Dictionary();
                                    $cursor = &$cursor[$name];
                                    $isInsideCompositeAttribute = true;
                                }
                            }
                        }
                        $isTerminalValue = $property instanceof SQLColumn || $propertyDescription instanceof ExpressionDescription;
                        if ($isTerminalValue) {
                            if ($cursor instanceof ArrayClass) {
                                $cursorKey = $cursor->hash;
                                if ($property instanceof SQLPrimaryKey && !isset($toManyIndex[$cursorKey][(string)$value])) {
                                    $newElement = new Dictionary([$primaryKeyName => $value]);
                                    $cursor->append($newElement);
                                    $toManyIndex[$cursorKey][(string)$value] = $newElement;
                                }
                                $element = ($childrenID !== null ? ($toManyIndex[$cursorKey][(string)$childrenID] ?? null) : null) ?? $cursor->last;
                                $cursor = &$element;
                            }
                            if ($cursor instanceof Dictionary && $propertyDescription instanceof PropertyDescription) {
                                $cursor[$key] = $this->coerceExpressionValue($value, $propertyDescription);
                                if (!$isInsideCompositeAttribute && !$propertyDescription instanceof CompositeAttributeDescription) {
                                    if ($cursor !== $root) {
                                        $cursor[ManagedObjectParentIDKey] = $parentID;
                                    }
                                    $this->registerSnapshot($cursor);
                                    if (($entityName = $cursor[ManagedObjectEntityNameKey]) && ($id = $cursor[ManagedObjectObjectIDKey])) {
                                        $snapshotIndex["$entityName:$id"] = $cursor;
                                    }
                                }
                            }
                        }
                    }
                    unset($cursor);
                    $cursorEntity = $entity;
                }
                assert($root instanceof Dictionary);
                $this->registerSnapshot($root);
                $byRootIDResult[$rootID] = $root;
            }
        } while ($statement->nextRowset() && $statement->columnCount());
        return $byRootIDResult->values;
    }

    /**
     * @param ArrayClass<string> $keyPathComponents
     * @param Set<string> $nullPropertyPrefixes
     */
    private function updateNullPropertyPrefixes(ArrayClass $keyPathComponents, string $primaryKeyName, mixed $value, Set $nullPropertyPrefixes): void
    {
        if ($keyPathComponents[$keyPathComponents->indexBefore($keyPathComponents->endIndex)] === $primaryKeyName) {
            $propertyKeyPath = $keyPathComponents->dropLast(1)->join(".");
            if ($value instanceof Nil) {
                $nullPropertyPrefixes->insert($propertyKeyPath);
            } else {
                $nullPropertyPrefixes->remove($propertyKeyPath);
            }
        }
    }

    /**
     * O(1) lookup in the snapshot index. Returns a shallow clone with to-many
     * collections and to-one snapshots reset so each root builds its own subgraph.
     *
     * @param Dictionary<Dictionary<mixed>> $snapshotIndex
     * @return Dictionary<mixed>|null
     */
    private function cloneFromIndex(Dictionary $snapshotIndex, string $entityName, mixed $targetID): ?Dictionary
    {
        $snapshot = $snapshotIndex["$entityName:$targetID"];
        return $snapshot instanceof Dictionary ? $this->cloneSnapshotShallow($snapshot) : null;
    }

    /**
     * Copies scalar/atomic attributes and composite-attribute sub-Dictionaries
     * (those lacking an objectID). Skips to-many ArrayClass collections and
     * to-one snapshot Dictionaries (those with objectID): each root must rebuild
     * its own subgraph beneath the clone, otherwise mutations bleed across roots.
     *
     * @param Dictionary<mixed> $source
     * @return Dictionary<mixed>
     */
    private function cloneSnapshotShallow(Dictionary $source): Dictionary
    {
        $clone = new Dictionary();
        foreach ($source as $key => $value) {
            if ($value instanceof ArrayClass) {
                continue;
            }
            if ($value instanceof Dictionary && $value[ManagedObjectObjectIDKey]) {
                continue;
            }
            $clone[$key] = $value;
        }
        return $clone;
    }

    private function coerceExpressionValue(mixed $value, PropertyDescription $description): mixed
    {
        if ($description instanceof ExpressionDescription) {
            return ManagedObject::coercedValue($value, $description->resultType, isOptional: $description->isOptional);
        }
        if ($description instanceof AttributeDescription) {
            return ManagedObject::coercedValue($value, $description->type, isOptional: $description->isOptional);
        }
        return $value;
    }

    /**
     * @param Dictionary<mixed> $snapshot
     */
    private function registerSnapshot(Dictionary $snapshot): void
    {
        if ($snapshot[ManagedObjectObjectIDKey] && $snapshot[ManagedObjectEntityNameKey] && $snapshot[ManagedObjectVersionKey]) {
            $snapshot[ManagedObjectIsInsertedKey] = true;
            $snapshot[ManagedObjectIsFaultKey] = false;
            $snapshot[ManagedObjectFaultingStateKey] = ManagedObjectFaultingStateStable;
        }
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $snapshots
     * @return ArrayClass<ManagedObject>
     */
    private function managedObjectsFromSnapshots(ArrayClass $snapshots): ArrayClass
    {
        return $snapshots->map(function (Dictionary $snapshot): ManagedObject {
            $serialization = $this->request->serialization;
            /** @var string $entityName */
            $entityName = $snapshot[$this->sqlEntityForFetchRequest->entityKey->columnName] ?? fatal_error("invalid snapshot: {$this->sqlEntityForFetchRequest->entityKey->columnName} cannot be null");
            /** @var SQLEntity $entity */
            $entity = $this->sqlModel->entitiesByName[$entityName] ?? fatal_error("invalid snapshot: {$this->sqlEntityForFetchRequest->entityKey->columnName} $entityName cannot be null");
            $objectID = $this->sqlCore->objectID($entity->entityDescription, $snapshot[$entity->primaryKey->columnName]);
            $object = $this->context->object($objectID);
            $object->isSuppressingChangeNotifications = true;
            $object->isSuppressingKVO = true;
            if ($object->isStable) {
                $object->materializeFaultsFromSnapshot($snapshot);
            } else {
                $object->updateFromSnapshot($snapshot);
            }
            $object->isSuppressingKVO = false;
            if (!$object->isAwakeFromFetch) {
                $object->isAwakeFromFetch = true;
                $object->awakeFromFetch();
            }
            $object->isSuppressingChangeNotifications = false;
            /** @var ManagedObject */
            return $object->serialized($serialization);
        });
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $snapshots
     * @return ArrayClass<ManagedObjectID>
     */
    private function managedObjectIDsFromSnapshots(ArrayClass $snapshots): ArrayClass
    {
        return $snapshots->map(function (Dictionary $snapshot): ManagedObjectID {
            $entityName = $snapshot[$this->sqlEntityForFetchRequest->entityKey->columnName] ?? $this->sqlEntityForFetchRequest->entityDescription->name;
            /** @var SQLEntity $entity */
            $entity = $this->sqlModel->entitiesByName[$entityName] ?? fatal_error("invalid snapshot: entity \"$entityName\" does not exist");
            return $this->sqlCore->objectID($entity->entityDescription, $snapshot[$entity->primaryKey->columnName]);
        });
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $snapshots
     * @return ArrayClass<ManagedObject>|ArrayClass<ManagedObjectID>
     */
    private function mapSnapshotsToResult(ArrayClass $snapshots): ArrayClass
    {
        if ($this->request->includesPropertyValues) {
            $managedObjects = $this->managedObjectsFromSnapshots($snapshots);
            if ($this->request->resultType === FetchRequestResultType::managedObjectIDResultType) {
                return $managedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID);
            }
            return $managedObjects;
        }
        if ($this->request->resultType === FetchRequestResultType::managedObjectResultType) {
            return $this->managedObjectsFromSnapshots($snapshots);
        }
        return $this->managedObjectIDsFromSnapshots($snapshots);
    }

    #[Override]
    protected function executePrologue(): void
    {
        $this->duration = ProcessInfo::processInfo()->systemUptime;
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        $this->result = match ($this->request->resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType => $this->mapSnapshotsToResult($this->fetchSnapshotsFromStatement($this->queryStatement)),
            FetchRequestResultType::dictionaryResultType => $this->fetchSnapshotsFromStatement($this->queryStatement),
            FetchRequestResultType::countResultType => $this->request->resultType
                    |> human_readable_value(...)
                    |> (fn(string $x): string => sprintf("CoreData: annotation: invalid result type: %s", $x))
                    |> fatal_error(...),
        };
        return true;
    }

    #[Override]
    protected function executeEpilogue(): void
    {
        $this->duration = ProcessInfo::processInfo()->systemUptime - $this->duration;
        $level = $this->debugLevel->value;
        if ($level) {
            $message = sprintf("CoreData: annotation: total execution time: %s for %d %s", human_readable_time($this->duration), $this->result->count, pluralize("element", $this->result->count));
            if ($level > SQLDebugLevel::prettyFormatSQL->value) {
                $message .= "\n$this->result";
            }
            error_log($message);
            if ($level > SQLDebugLevel::includeResults->value) {
                $statement = $this->connection->execute(new SQLStatement("ANALYZE FORMAT=JSON {$this->fetchStatement->string}", $this->fetchStatement->arguments));
                error_log($statement->fetchColumn());
            }
        }
    }
}
