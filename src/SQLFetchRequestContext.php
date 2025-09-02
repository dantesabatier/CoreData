<?php

namespace Sabatier\CoreData;

use Override;
use PDOStatement;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\absolute_time_get_current;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_plural;
use function Sabatier\Foundation\human_readable_time;

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

    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    /**
     * @param PDOStatement $statement
     * @return ArrayClass<Dictionary<mixed>>
     */
    private function dictionaryResults(PDOStatement $statement): ArrayClass
    {
        /** @var Dictionary<Dictionary<mixed>> $map */
        $map = new Dictionary();
        /** @var Set<string> $trackableKeys */
        $trackableKeys = new Set();
        do {
            /** @var array<string, mixed> $data */
            while ($data = $statement->fetch()) {
                $entityName = $data[SQLEntity::entityKeyName] ?? $this->request->entity->name;
                /** @var SQLEntity $entity */
                $entity = $this->sqlModel->entitiesByName[$entityName] ?? fatal_error("Entity \"$entityName\" does not exists");
                $currentEntity = $entity;
                $referenceObject = (string)$data[$entity->primaryKey->columnName];
                /** @var Dictionary<mixed> $representation */
                $representation = $map[$referenceObject] ?? new Dictionary();
                foreach ($data as $pattern => $value) {
                    $value ??= Nil::nil();
                    $keys = new ArrayClass(explode("_", $pattern));
                    if ($keys->count >= 3) {
                        $keys->removeAt(0);
                    }
                    $propertyKeys = $keys->dropLast(1);
                    if ($keys[$keys->indexBefore($keys->endIndex)] === $currentEntity->primaryKey->columnName) {
                        $keyPath = $propertyKeys->join(".");
                        if ($value instanceof Nil) {
                            $trackableKeys->append($keyPath);
                        } else {
                            $trackableKeys->remove($keyPath);
                        }
                    }
                    $keyPath = $keys->join(".");
                    if ($trackableKeys->contains(fn(string $prefix): bool => str_starts_with($keyPath, $prefix))) {
                        continue;
                    }
                    $relationship = null;
                    $current = &$representation;
                    $currentKey = $currentEntity->primaryKey->columnName;
                    $currentID = $data[$currentKey] ?? null;
                    $childrenKeys = new ArrayClass();
                    if (!$propertyKeys->isEmpty) {
                        $childrenKeys->append($currentEntity->tableName);
                        $childrenKeys->appendContentsOf($propertyKeys);
                        $childrenKeys->append($currentEntity->primaryKey->columnName);
                    }
                    $parentKeys = new ArrayClass();
                    if (!$propertyKeys->isEmpty) {
                        $parentKeys->appendContentsOf(new ArrayClass($propertyKeys)->dropLast(1));
                        if (!$parentKeys->isEmpty) {
                            $parentKeys->insertAt($currentEntity->tableName, 0);
                            $parentKeys->append($currentEntity->primaryKey->columnName);
                        }
                    }
                    $childrenKey = $childrenKeys->join("_");
                    $childrenID = $data[$childrenKey] ?? null;
                    $parentKey = $parentKeys->join("_");
                    $parentID = $data[$parentKey] ?? $currentID;
                    foreach ($keys as $key) {
                        $property = $currentEntity->propertiesByName[$key] ?? $currentEntity->compositeAttributeNameToSQLProperty[$key];
                        $propertyDescription = $property?->propertyDescription ?? $this->request->propertiesToFetch?->first(fn(string|PropertyDescription $property): bool => $property instanceof PropertyDescription ? $property->name === $key : $property === $key);
                        if ($property instanceof SQLRelationship || ($property instanceof SQLAttribute && $property->isCompositeAttribute)) {
                            if ($current instanceof ArrayClass) {
                                $element = $current->first(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $parentID);
                                if (!$element) {
                                    $last = $current->last;
                                    if ($last instanceof Dictionary) {
                                        $lastValue = $last->valueForKey($key);
                                        if ($lastValue instanceof ArrayClass) {
                                            $last[$key] = $lastValue->filter(fn(Dictionary $dictionary): bool => $dictionary["parentID"] === $last[$currentEntity->primaryKey->columnName]);
                                        }
                                        $element = $last;
                                    }
                                }
                                $current = &$element;
                            }
                            if ($current instanceof Dictionary) {
                                if ($property instanceof SQLToOne) {
                                    $current[$key] ??= new Dictionary();
                                } elseif ($property instanceof SQLAttribute) {
                                    $current[$key] ??= new Dictionary();
                                } else {
                                    $current[$key] ??= new ArrayClass();
                                }
                                $current = &$current[$key];
                            }
                            if ($property instanceof SQLRelationship) {
                                $relationship = $property;
                                $currentEntity = $relationship->destinationEntity;
                            }
                        }
                        if ($property instanceof SQLColumn || $propertyDescription instanceof ExpressionDescription) {
                            if ($current instanceof ArrayClass) {
                                if ($property instanceof SQLPrimaryKey && !$current->contains(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $value)) {
                                    $current->append(new Dictionary([$currentEntity->primaryKey->columnName => $value]));
                                }
                                $element = $current->first(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $childrenID) ?? $current->last;
                                $current = &$element;
                            }
                            if ($current instanceof Dictionary) {
                                if ($propertyDescription instanceof ExpressionDescription) {
                                    $value = ManagedObject::coercedValue($value, $propertyDescription->resultType, isOptional: $propertyDescription->isOptional);
                                }
                                $current[$key] = $value;
                                if (!$propertyDescription instanceof CompositeAttributeDescription && $this->request->resultType !== FetchRequestResultType::dictionaryResultType) {
                                    $current["isInserted"] = true;
                                    $current["isFault"] = false;
                                    $current["faultingState"] = 0;
                                    if ($current !== $representation) {
                                        $current["parentID"] = $parentID;
                                    }
                                }
                            }
                        }
                    }
                    unset($current);
                    $currentEntity = $entity;
                }
                if ($this->request->resultType !== FetchRequestResultType::dictionaryResultType) {
                    $representation["isInserted"] = true;
                    $representation["faultingState"] = 0;
                    $representation["isFault"] = $this->request->returnsObjectsAsFaults;
                }
                $map[$referenceObject] = $representation;
            }
        } while ($statement->nextRowset() && $statement->columnCount());
        return $map->values;
    }

    /**
     * @param PDOStatement $statement
     * @return ArrayClass<Number>
     */
    private function numericalResults(PDOStatement $statement): ArrayClass
    {
        return new ArrayClass([new Number((int)$statement->fetchColumn())]);
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
    public function executeRequestCore(): bool
    {
        $time = absolute_time_get_current();
        $statement = $this->connection->execute($this->fetchStatement);
        $this->result = match ($this->request->resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType => $this->managedResults($this->dictionaryResults($statement)),
            FetchRequestResultType::dictionaryResultType => $this->dictionaryResults($statement),
            FetchRequestResultType::countResultType => $this->numericalResults($statement),
        };
        if ($this->debugLogLevel) {
            $message = sprintf("CoreData: annotation: total execution time: %s for %d %s", human_readable_time(absolute_time_get_current() - $time), $this->result->count, human_readable_plural("element", $this->result->count));
            if ($this->debugLogLevel > 3) {
                $message .= "\n$this->result";
            }
            error_log($message);
            if ($this->debugLogLevel > 4) {
                $statement = $this->connection->execute(new SQLStatement("ANALYZE FORMAT=JSON {$this->fetchStatement->string}", $this->fetchStatement->arguments));
                error_log($statement->fetchColumn());
            }
        }
        return true;
    }
}
