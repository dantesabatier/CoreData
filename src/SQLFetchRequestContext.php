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
     * @param PDOStatement $execute
     * @return ArrayClass<Dictionary<mixed>>
     */
    public function dictionaries(PDOStatement $execute): ArrayClass
    {
        /** @var Dictionary<Dictionary<mixed>> $map */
        $map = new Dictionary();
        /** @var Set<string> $keyPaths */
        $keyPaths = new Set();
        do {
            /** @var array<string, mixed> $data */
            while ($data = $execute->fetch()) {
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
                            $keyPaths->append($keyPath);
                        } else {
                            $keyPaths->remove($keyPath);
                        }
                    }
                    $keyPath = $keys->join(".");
                    if ($keyPaths->contains(fn(string $prefix): bool => str_starts_with($keyPath, $prefix))) {
                        continue;
                    }
                    $relationship = null;
                    $current = &$representation;
                    $currentKeys = new ArrayClass([$currentEntity->tableName]);
                    $currentKeys->appendContentsOf($propertyKeys);
                    $parentKeys = new ArrayClass();
                    $parentKeys->appendContentsOf($currentKeys->dropLast(1));
                    $parentKeys->append($currentEntity->primaryKey->columnName);
                    $parentKey = $parentKeys->join("_");
                    $currentKeys->append($currentEntity->primaryKey->columnName);
                    $currentKey = $currentKeys->join("_");
                    $currentID = $data[$currentKey] ?? null;
                    $parentID = $data[$parentKey] ?? null;
                    foreach ($keys as $key) {
                        $property = $currentEntity->propertiesByName[$key] ?? $currentEntity->compositeAttributeNameToSQLProperty[$key];
                        $propertyDescription = $property?->propertyDescription ?? $this->request->propertiesToFetch?->first(fn(string|PropertyDescription $property): bool => $property instanceof PropertyDescription ? $property->name === $key : $property === $key);
                        if ($property instanceof SQLRelationship || ($property instanceof SQLAttribute && $property->isCompositeAttribute)) {
                            if ($current instanceof ArrayClass && !$current->isEmpty) {
                                $element = $current->first(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $parentID && $dictionary[$currentEntity->entityKey->columnName] === $currentEntity->entityDescription->name) ?? $current->last;
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
                                if ($property instanceof SQLPrimaryKey && !$current->contains(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $currentID && $dictionary[$currentEntity->entityKey->columnName] === $currentEntity->entityDescription->name)) {
                                    $dictionary = new Dictionary([$currentEntity->primaryKey->columnName => $currentID, $currentEntity->entityKey->columnName => $currentEntity->entityDescription->name]);
                                    $current->append($dictionary);
                                }
                                if (!$current->isEmpty) {
                                    $element = $current->first(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $currentID && $dictionary[$currentEntity->entityKey->columnName] === $currentEntity->entityDescription->name) ?? $current->last;
                                    $current = &$element;
                                }
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
        } while ($execute->nextRowset() && $execute->columnCount());
        return $map->values;
    }

    /**
     * @param PDOStatement $execute
     * @return ArrayClass<Number>
     */
    public function numbers(PDOStatement $execute): ArrayClass
    {
        return new ArrayClass([new Number((int)$execute->fetchColumn())]);
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $dictionaryRepresentations
     * @return ArrayClass<ManagedObject>
     */
    private function managedObjects(ArrayClass $dictionaryRepresentations): ArrayClass
    {
        return $dictionaryRepresentations->map(function (Dictionary $dictionary): ManagedObject {
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
     * @param ArrayClass<Dictionary<mixed>> $dictionaryRepresentations
     * @return ArrayClass<ManagedObjectID>
     */
    public function managedObjectIDs(ArrayClass $dictionaryRepresentations): ArrayClass
    {
        return $dictionaryRepresentations->map(fn(Dictionary $dictionary): ManagedObjectID => $this->sqlCore->objectID($this->sqlEntityForFetchRequest->entityDescription, $dictionary[$this->sqlEntityForFetchRequest->primaryKey->columnName]));
    }

    /**
     * @param ArrayClass<Dictionary<mixed>> $representations
     * @return ArrayClass<ManagedObject>|ArrayClass<ManagedObjectID>
     */
    public function values(ArrayClass $representations): ArrayClass
    {
        if ($this->request->includesPropertyValues) {
            $managedObjects = $this->managedObjects($representations);
            if ($this->request->resultType === FetchRequestResultType::managedObjectIDResultType) {
                return $managedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID);
            }
            return $managedObjects;
        }
        if ($this->request->resultType === FetchRequestResultType::managedObjectResultType) {
            return $this->managedObjects($representations);
        }
        return $this->managedObjectIDs($representations);
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        $time = absolute_time_get_current();
        $execute = $this->connection->execute($this->fetchStatement);
        $values = match ($this->request->resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType, FetchRequestResultType::dictionaryResultType => $this->dictionaries($execute),
            FetchRequestResultType::countResultType => $this->numbers($execute),
        };
        if ($this->request->resultType === FetchRequestResultType::managedObjectResultType || $this->request->resultType === FetchRequestResultType::managedObjectIDResultType) {
            $values = $this->values($values);
        }
        $this->result = $values;
        if ($this->debugLogLevel) {
            $message = sprintf("CoreData: annotation: total execution time: %s for %d %s", human_readable_time(absolute_time_get_current() - $time), $this->result->count, human_readable_plural("element", $this->result->count));
            if ($this->debugLogLevel > 3) {
                $message .= "\n$this->result";
            }
            error_log($message);
            if ($this->debugLogLevel > 4) {
                $execute = $this->connection->execute(new SQLStatement("ANALYZE FORMAT=JSON {$this->fetchStatement->string}", $this->fetchStatement->arguments));
                error_log($execute->fetchColumn());
            }
        }
        return true;
    }
}
