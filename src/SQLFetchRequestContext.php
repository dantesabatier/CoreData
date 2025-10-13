<?php

namespace Sabatier\CoreData;

use Override;
use PDOStatement;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\Slice;
use function Sabatier\Foundation\absolute_time_get_current;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_plural;
use function Sabatier\Foundation\human_readable_time;
use function Sabatier\Foundation\human_readable_value;

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
    private float $timestamp = 0;
    private(set) PDOStatement $statement {
        get => $this->statement ??= $this->connection->execute($this->fetchStatement);
    }

    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    private function coerceExpressionValueIfNeeded(mixed $value, PropertyDescription $description): mixed
    {
        if ($description instanceof ExpressionDescription) {
            return ManagedObject::coercedValue($value, $description->resultType, isOptional: $description->isOptional);
        }
        return $value;
    }

    /**
     * @param string $pattern
     * @return ArrayClass<string>
     */
    private function split(string $pattern): ArrayClass
    {
        $keys = new ArrayClass(explode("_", $pattern));
        if ($keys->count >= 3) {
            $keys->removeAt(0);
        }
        return $keys;
    }

    /**
     * @param Slice<string> $propertyKeys
     * @param SQLEntity $currentEntity
     * @param array<string, mixed> $rowData
     * @return array{int|null, int|null}
     */
    private function buildRelationalIDSets(Slice $propertyKeys, SQLEntity $currentEntity, array $rowData): array
    {
        /** @var ArrayClass<string> $childrenKeys */
        $childrenKeys = new ArrayClass();
        if (!$propertyKeys->isEmpty) {
            $childrenKeys->append($currentEntity->tableName);
            $childrenKeys->appendContentsOf($propertyKeys);
            $childrenKeys->append($currentEntity->primaryKey->columnName);
        }
        /** @var ArrayClass<string> $parentKeys */
        $parentKeys = new ArrayClass();
        if (!$propertyKeys->isEmpty) {
            $parentKeys->appendContentsOf(new ArrayClass($propertyKeys)->dropLast(1));
            if (!$parentKeys->isEmpty) {
                $parentKeys->insertAt($currentEntity->tableName, 0);
                $parentKeys->append($currentEntity->primaryKey->columnName);
            }
        }
        $currentKey = $currentEntity->primaryKey->columnName;
        $currentID = $rowData[$currentKey] ?? null;
        $childrenKey = $childrenKeys->join("_");
        $childrenID = $rowData[$childrenKey] ?? null;
        $parentKey = $parentKeys->join("_");
        $parentID = $rowData[$parentKey] ?? $currentID;
        return [$childrenID, $parentID];
    }

    /**
     * @param PDOStatement $statement
     * @return ArrayClass<Dictionary<mixed>>
     */
    private function dictionaryResults(PDOStatement $statement): ArrayClass
    {
        /** @var Dictionary<Dictionary<mixed>> $byRootIDResult */
        $byRootIDResult = new Dictionary();
        /** @var Set<string> $nullPropertyPrefixes */
        $nullPropertyPrefixes = new Set();
        do {
            /** @var array<string, mixed> $row */
            while ($row = $statement->fetch()) {
                $entityNameFromRow = $row[SQLEntity::entityKeyName] ?? $this->request->entity->name;
                /** @var SQLEntity $entity */
                $entity = $this->sqlModel->entitiesByName[$entityNameFromRow] ?? fatal_error("Entity \"$entityNameFromRow\" does exists");
                $cursorEntity = $entity;
                $rootID = (string)$row[$entity->primaryKey->columnName];
                /** @var Dictionary<mixed> $root */
                $root = $byRootIDResult[$rootID] ?? new Dictionary();
                $isNonDictionaryResultType = ($this->request->resultType !== FetchRequestResultType::dictionaryResultType);
                foreach ($row as $pattern => $value) {
                    $value ??= Nil::nil();
                    $keyPathComponents = $this->split($pattern);
                    $propertyKeyPathComponents = $keyPathComponents->dropLast(1);
                    $primaryKeyName = $cursorEntity->primaryKey->columnName;
                    if ($keyPathComponents[$keyPathComponents->indexBefore($keyPathComponents->endIndex)] === $primaryKeyName) {
                        $propertyKeyPath = $propertyKeyPathComponents->join(".");
                        if ($value instanceof Nil) {
                            $nullPropertyPrefixes->append($propertyKeyPath);
                        } else {
                            $nullPropertyPrefixes->remove($propertyKeyPath);
                        }
                    }
                    $keyPath = $keyPathComponents->join(".");
                    $hasNullifiedPrefix = $nullPropertyPrefixes->contains(fn(string $prefix): bool => str_starts_with($keyPath, $prefix));
                    if ($hasNullifiedPrefix) {
                        continue;
                    }
                    $relationship = null;
                    $cursor = &$root;
                    [$childrenID, $parentID] = $this->buildRelationalIDSets($propertyKeyPathComponents, $cursorEntity, $row);
                    foreach ($keyPathComponents as $key) {
                        $property = $cursorEntity->propertiesByName[$key] ?? $cursorEntity->compositeAttributeNameToSQLProperty[$key];
                        $propertyDescription = $property?->propertyDescription ?? $this->request->propertiesToFetch?->first(fn(string|PropertyDescription $p): bool => $p instanceof PropertyDescription ? $p->name === $key : $p === $key);
                        $isNavigational = ($property instanceof SQLRelationship) || ($property instanceof SQLAttribute && $property->isCompositeAttribute);
                        if ($isNavigational) {
                            if ($cursor instanceof ArrayClass) {
                                $element = $cursor->first(fn(Dictionary $dictionary): bool => $dictionary[$primaryKeyName] === $parentID);
                                if (!$element) {
                                    $last = $cursor->last;
                                    if ($last instanceof Dictionary) {
                                        $lastID = $last[$primaryKeyName];
                                        $lastValue = $last->valueForKey($key);
                                        if ($lastValue instanceof ArrayClass) {
                                            $last[$key] = $lastValue->filter(fn(Dictionary $dictionary): bool => $dictionary["parentID"] === $lastID);
                                        } elseif ($lastValue instanceof Dictionary) {
                                            if ($lastValue["parentID"] !== $lastID) {
                                                $last->removeValueForKey($key);
                                            }
                                        }
                                        $element = $last;
                                    }
                                }
                                $cursor = &$element;
                            }
                            if ($cursor instanceof Dictionary) {
                                if ($property instanceof SQLToOne || $property instanceof SQLAttribute) {
                                    $cursor[$key] ??= new Dictionary();
                                } else {
                                    $cursor[$key] ??= new ArrayClass();
                                }
                                $cursor = &$cursor[$key];
                            }
                            if ($property instanceof SQLRelationship) {
                                $relationship = $property;
                                $cursorEntity = $relationship->destinationEntity;
                                $primaryKeyName = $cursorEntity->primaryKey->columnName;
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
                            if ($cursor instanceof Dictionary) {
                                $cursor[$key] = $this->coerceExpressionValueIfNeeded($value, $propertyDescription);
                                if ($cursor !== $root) {
                                    $cursor["parentID"] = $parentID;
                                }
                                if (!$propertyDescription instanceof CompositeAttributeDescription && $isNonDictionaryResultType) {
                                    $cursor["isInserted"] = true;
                                    $cursor["isFault"] = false;
                                    $cursor["faultingState"] = 0;
                                }
                            }
                        }
                    }
                    unset($cursor);
                    $cursorEntity = $entity;
                }
                assert($root instanceof Dictionary);
                if ($isNonDictionaryResultType) {
                    $root["isInserted"] = true;
                    $root["faultingState"] = 0;
                    $root["isFault"] = $this->request->returnsObjectsAsFaults;
                }
                $byRootIDResult[$rootID] = $root;
            }
        } while ($statement->nextRowset() && $statement->columnCount());
        return $byRootIDResult->values;
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $dictionaries
     * @return ArrayClass<ManagedObject>
     */
    private function managedObjects(ArrayClass $dictionaries): ArrayClass
    {
        return $dictionaries->map(function (Dictionary $dictionary): ManagedObject {
            /** @var SQLEntity $entity */
            $entity = $this->sqlModel->entitiesByName[$dictionary[$this->sqlEntityForFetchRequest->entityKey->columnName]];
            $object = $this->context->object($this->sqlCore->objectID($entity->entityDescription, $dictionary[$entity->primaryKey->columnName]));
            $object->isSuppressingChangeNotifications = true;
            $object->isSuppressingKVO = true;
            $object->setValuesForKeys($dictionary);
            $object->isSuppressingKVO = false;
            if (!$object->isAwakeFromFetch) {
                $object->isAwakeFromFetch = true;
                $object->awakeFromFetch();
            }
            $object->isSuppressingChangeNotifications = false;
            /** @var ManagedObject */
            return $object->serialized($this->request->serialization);
        });
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $dictionaries
     * @return ArrayClass<ManagedObjectID>
     */
    private function managedObjectIDs(ArrayClass $dictionaries): ArrayClass
    {
        return $dictionaries->map(fn(Dictionary $dictionary): ManagedObjectID => $this->sqlCore->objectID($this->sqlEntityForFetchRequest->entityDescription, $dictionary[$this->sqlEntityForFetchRequest->primaryKey->columnName]));
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $dictionaries
     * @return ArrayClass<ManagedObject>|ArrayClass<ManagedObjectID>
     */
    private function managedResults(ArrayClass $dictionaries): ArrayClass
    {
        if ($this->request->includesPropertyValues) {
            $managedObjects = $this->managedObjects($dictionaries);
            if ($this->request->resultType === FetchRequestResultType::managedObjectIDResultType) {
                return $managedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID);
            }
            return $managedObjects;
        }
        if ($this->request->resultType === FetchRequestResultType::managedObjectResultType) {
            return $this->managedObjects($dictionaries);
        }
        return $this->managedObjectIDs($dictionaries);
    }

    #[Override]
    protected function executePrologue(): void
    {
        $this->timestamp = absolute_time_get_current();
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        $this->result = match ($this->request->resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType => $this->managedResults($this->dictionaryResults($this->statement)),
            FetchRequestResultType::dictionaryResultType => $this->dictionaryResults($this->statement),
            FetchRequestResultType::countResultType => fatal_error(sprintf("CoreData: annotation: invalid result type: %s", human_readable_value($this->request->resultType))),
        };
        return true;
    }

    #[Override]
    protected function executeEpilogue(): void
    {
        if ($this->debugLogLevel) {
            $message = sprintf("CoreData: annotation: total execution time: %s for %d %s", human_readable_time(absolute_time_get_current() - $this->timestamp), $this->result->count, human_readable_plural("element", $this->result->count));
            if ($this->debugLogLevel > 3) {
                $message .= "\n$this->result";
            }
            error_log($message);
            if ($this->debugLogLevel > 4) {
                $statement = $this->connection->execute(new SQLStatement("ANALYZE FORMAT=JSON {$this->fetchStatement->string}", $this->fetchStatement->arguments));
                error_log($statement->fetchColumn());
            }
        }
    }
}
