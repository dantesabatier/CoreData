<?php

namespace Sabatier\CoreData;

use Override;
use PDOStatement;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\absolute_time_get_current;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_time;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\pluralize;
use function Sabatier\Foundation\substring_to_index;

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
        get => $this->fetchStatement ??= $this->generator->statement ?? fatal_error();
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

    private function coerceExpressionValue(mixed $value, PropertyDescription $description): mixed
    {
        if ($description instanceof ExpressionDescription) {
            return ManagedObject::coercedValue($value, $description->resultType, isOptional: $description->isOptional);
        }
        if ($description instanceof AttributeDescription && !$value instanceof Nil) {
            return ManagedObject::coercedValue($value, $description->type, isOptional: $description->isOptional);
        }
        return $value;
    }

    /**
     * @param string $pattern
     * @return ArrayClass<string>
     */
    private function splitKeyPathPattern(string $pattern): ArrayClass
    {
        /** @var array<string, ArrayClass<string>> $cache */
        static $cache = [];
        if (isset($cache[$pattern])) {
            return clone $cache[$pattern];
        }
        $parts = explode("_", $pattern);
        if (count($parts) >= 3) {
            array_shift($parts);
        }
        return $cache[$pattern] = new ArrayClass($parts);
    }

    /**
     * @param PDOStatement $statement
     * @return ArrayClass<Dictionary<mixed>>
     */
    private function fetchSnapshotsFromStatement(PDOStatement $statement): ArrayClass
    {
        /** @var Dictionary<Dictionary<mixed>> $byRootIDResult */
        $byRootIDResult = new Dictionary();
        /** @var Set<string> $nullPropertyPrefixes */
        $nullPropertyPrefixes = new Set();
        $isNonDictionaryResultType = ($this->request->resultType !== FetchRequestResultType::dictionaryResultType);
        do {
            /** @var EntityDescription $entityDescription */
            $entityDescription = $this->request->entity;
            /** @var array<string, mixed> $row */
            while ($row = $statement->fetch()) {
                $entityNameFromRow = $row[ManagedObjectEntityNameKey] ?? $entityDescription->name;
                /** @var SQLEntity $entity */
                $entity = $this->sqlModel->entitiesByName[$entityNameFromRow] ?? fatal_error("Entity \"$entityNameFromRow\" does not exist");
                $cursorEntity = $entity;
                $rootID = (string)$row[$entity->primaryKey->columnName];
                /** @var Dictionary<mixed> $root */
                $root = $byRootIDResult[$rootID] ?? new Dictionary();
                foreach ($row as $pattern => $value) {
                    $value ??= Nil::nil();
                    $keyPathComponents = $this->splitKeyPathPattern($pattern);
                    $propertyKeyPathComponents = $keyPathComponents->dropLast(1);
                    $primaryKeyName = $cursorEntity->primaryKey->columnName;
                    if ($keyPathComponents[$keyPathComponents->indexBefore($keyPathComponents->endIndex)] === $primaryKeyName) {
                        $propertyKeyPath = $propertyKeyPathComponents->join(".");
                        if ($value instanceof Nil) {
                            $nullPropertyPrefixes->insert($propertyKeyPath);
                        } else {
                            $nullPropertyPrefixes->remove($propertyKeyPath);
                        }
                    }
                    $keyPath = $keyPathComponents->join(".");
                    if ($nullPropertyPrefixes->contains(fn(string $prefix): bool => $keyPath === $prefix || str_starts_with($keyPath, "$prefix."))) {
                        continue;
                    }
                    $cursor = &$root;
                    $lastUnderscorePos = strrpos($pattern, "_");
                    $basePathPrefix = substring_to_index($pattern, $lastUnderscorePos);
                    $childIDKey = "{$basePathPrefix}_$primaryKeyName";
                    $childrenID = $row[$childIDKey] ?? null;
                    $secondLastUnderscorePos = strrpos($basePathPrefix, "_");
                    if ($secondLastUnderscorePos !== false) {
                        $parentPathPrefix = substring_to_index($basePathPrefix, $secondLastUnderscorePos);
                        $parentIDKey = "{$parentPathPrefix}_$primaryKeyName";
                        $parentID = $row[$parentIDKey] ?? null;
                    } else {
                        $parentID = $rootID;
                    }
                    $isInsideCompositeAttribute = false;
                    foreach ($keyPathComponents as $key) {
                        $property = $cursorEntity->propertiesByName[$key] ?? $cursorEntity->compositeAttributeNameToSQLProperty[$key];
                        /** @var PropertyDescription|null $propertyDescription */
                        $propertyDescription = $property?->propertyDescription ?? $this->request->propertiesToFetch?->first(fn(string|PropertyDescription $p): bool => $p instanceof PropertyDescription ? $p->name === $key : $p === $key);
                        $isRelationship = $property instanceof SQLRelationship;
                        $isCompositeAttribute = ($property instanceof SQLAttribute && $property->isCompositeAttribute);
                        $isNavigational = ($isRelationship || $isCompositeAttribute);
                        if ($isNavigational) {
                            if ($cursor instanceof ArrayClass) {
                                $element = $cursor->first(fn(Dictionary $dictionary): bool => $dictionary[$primaryKeyName] === $parentID);
                                if (!$element) {
                                    $last = $cursor->last;
                                    if ($last instanceof Dictionary) {
                                        $lastID = $last[$primaryKeyName];
                                        $lastValue = $last[$key];
                                        if ($lastValue instanceof ArrayClass) {
                                            $last[$key] = $lastValue->filter(fn(Dictionary $dictionary): bool => $dictionary[ManagedObjectParentIDKey] === $lastID);
                                        } elseif ($lastValue instanceof Dictionary) {
                                            if ($lastValue[ManagedObjectParentIDKey] !== $lastID) {
                                                $last->removeValueForKey($key);
                                            }
                                        }
                                        $element = $last;
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
                                if ($property instanceof SQLPrimaryKey && !$cursor->contains(fn(Dictionary $dictionary): bool => $dictionary[$primaryKeyName] === $value)) {
                                    $cursor->append(new Dictionary([$primaryKeyName => $value]));
                                }
                                $element = $cursor->first(fn(Dictionary $dictionary): bool => $dictionary[$primaryKeyName] === $childrenID) ?? $cursor->last;
                                $cursor = &$element;
                            }
                            if ($cursor instanceof Dictionary && $propertyDescription instanceof PropertyDescription) {
                                $cursor[$key] = $this->coerceExpressionValue($value, $propertyDescription);
                                if (!$isInsideCompositeAttribute && !$propertyDescription instanceof CompositeAttributeDescription) {
                                    if ($cursor !== $root) {
                                        $cursor[ManagedObjectParentIDKey] = $parentID;
                                    }
                                    if ($isNonDictionaryResultType && $cursor[ManagedObjectObjectIDKey] && $cursor[ManagedObjectEntityNameKey] && $cursor[ManagedObjectVersionKey]) {
                                        $cursor[ManagedObjectIsInsertedKey] = true;
                                        $cursor[ManagedObjectIsFaultKey] = false;
                                        $cursor[ManagedObjectFaultingStateKey] = 0;
                                    }
                                }
                            }
                        }
                    }
                    unset($cursor);
                    $cursorEntity = $entity;
                }
                assert($root instanceof Dictionary);
                if ($isNonDictionaryResultType && $root[ManagedObjectObjectIDKey] && $root[ManagedObjectEntityNameKey] && $root[ManagedObjectVersionKey]) {
                    $root[ManagedObjectIsInsertedKey] = true;
                    $root[ManagedObjectFaultingStateKey] = 0;
                    $root[ManagedObjectIsFaultKey] = $this->request->returnsObjectsAsFaults;
                }
                $byRootIDResult[$rootID] = $root;
            }
        } while ($statement->nextRowset() && $statement->columnCount());
        return $byRootIDResult->values;
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $snapshots
     * @return ArrayClass<ManagedObject>
     */
    private function managedObjectsFromSnapshots(ArrayClass $snapshots): ArrayClass
    {
        return $snapshots->map(function (Dictionary $snapshot): ManagedObject {
            $serialization = $this->request->serialization;
            /** @var SQLEntity $entity */
            $entity = $this->sqlModel->entitiesByName[$snapshot[$this->sqlEntityForFetchRequest->entityKey->columnName]];
            $object = $this->context->object($this->sqlCore->objectID($entity->entityDescription, $snapshot[$entity->primaryKey->columnName]));
            if ($this->request->includesPendingChanges && $object->isStable) {
                return $object->serialized($serialization);
            }
            $object->isSuppressingChangeNotifications = true;
            $object->isSuppressingKVO = true;
            $object->updateFromSnapshot($snapshot);
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
        return $snapshots->map(fn(Dictionary $snapshot): ManagedObjectID => $this->sqlCore->objectID($this->sqlEntityForFetchRequest->entityDescription, $snapshot[$this->sqlEntityForFetchRequest->primaryKey->columnName]));
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
        $this->duration = absolute_time_get_current();
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
        $this->duration = absolute_time_get_current() - $this->duration;
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
