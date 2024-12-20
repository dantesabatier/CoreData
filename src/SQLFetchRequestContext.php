<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\absolute_time_get_current;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_time;

/** @internal */
class SQLFetchRequestContext extends SQLStoreRequestContext
{
    public SQLModel $sqlModel {
        get => $this->sqlCore->model;
    }
    private(set) SQLEntity $sqlEntityForFetchRequest {
        get => $this->sqlEntityForFetchRequest ??= $this->sqlModel->entity($this->request->entity->name) ?? fatal_error("Entity \"{$this->request->entity->name}\" does not exists");
    }
    private(set) SQLStatement $fetchStatement {
        get => $this->fetchStatement ??= $this->generator->statement ?? fatal_error();
    }
    public FetchRequest $request {
        get {
            /** @var FetchRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }

    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        $time = absolute_time_get_current();
        $execute = $this->connection->execute($this->fetchStatement);
        /** @var ArrayClass<Dictionary<mixed>|Number> $values */
        $values = match ($this->request->resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType, FetchRequestResultType::dictionaryResultType => (function () use ($execute): ArrayClass {
                /** @var Dictionary<Dictionary<mixed>> $map */
                $map = new Dictionary();
                /** @var Set<string> $keyPaths */
                $keyPaths = new Set();
                do {
                    /** @var array<string, mixed> $data */
                    while ($data = $execute->fetch()) {
                        $entityName = $data[SQLEntity::entityKeyName] ?? $this->request->entity->name;
                        /** @var SQLEntity $entity */
                        $entity = $this->sqlModel->entitiesByName[$entityName];
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
                            if ($keys[$keys->indexBefore($keys->endIndex)] === $currentEntity->primaryKey->columnName) {
                                $sliceOfKeys = $keys->dropLast(1);
                                $keyPath = $sliceOfKeys->join(".");
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
                            $parentKeys = new ArrayClass([$entityName]);
                            $parentKeys->appendContentsOf($keys->dropLast(1));
                            $parentKeys->append($currentEntity->primaryKey->columnName);
                            $parentKey = $parentKeys->join("_");
                            $parentID = $data[$parentKey] ?? null;
                            $parentEntityName = $data[$currentEntity->entityKey->columnName] ?? null;
                            foreach ($keys as $key) {
                                $property = $currentEntity->propertiesByName[$key] ?? $currentEntity->compositeAttributeNameToSQLProperty[$key];
                                $propertyDescription = $property?->propertyDescription ?? $this->request->propertiesToFetch?->first(fn(string|PropertyDescription $property): bool => $property instanceof PropertyDescription ? $property->name === $key : $property === $key);
                                if ($property instanceof SQLRelationship || ($property instanceof SQLAttribute && $property->isCompositeAttribute)) {
                                    if ($current instanceof ArrayClass && !$current->isEmpty) {
                                        $parent = $current->first(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $parentID && $dictionary[$currentEntity->entityKey->columnName] === $parentEntityName) ?? $current[$current->indexBefore($current->endIndex)];
                                        /** @psalm-suppress UnsupportedReferenceUsage */
                                        $current = &$parent;
                                    }
                                    if ($current instanceof Dictionary) {
                                        if ($property instanceof SQLToOne) {
                                            $current[$key] ??= new Dictionary();
                                            $current = &$current[$key];
                                        } elseif ($property instanceof SQLAttribute) {
                                            $current[$propertyDescription->name] ??= new Dictionary();
                                            $current = &$current[$propertyDescription->name];
                                        } else {
                                            $current[$key] ??= new ArrayClass();
                                            $current = &$current[$key];
                                        }
                                    }
                                    if ($property instanceof SQLRelationship) {
                                        $relationship = $property;
                                        $currentEntity = $relationship->destinationEntity;
                                    }
                                }
                                if ($property instanceof SQLColumn || $propertyDescription instanceof ExpressionDescription) {
                                    if ($current instanceof ArrayClass) {
                                        if ($property instanceof SQLPrimaryKey && !$current->contains(fn(Dictionary $dictionary): bool => $dictionary[$key] === $value)) {
                                            $current[] = new Dictionary([$currentEntity->primaryKey->columnName => $value, $currentEntity->entityKey->columnName => $currentEntity->entityDescription->name]);
                                        }
                                        if (!$current->isEmpty) {
                                            $parent = $current->first(fn(Dictionary $dictionary): bool => $dictionary[$currentEntity->primaryKey->columnName] === $parentID && $dictionary[$currentEntity->entityKey->columnName] === $parentEntityName) ?? $current[$current->indexBefore($current->endIndex)];
                                            /** @psalm-suppress UnsupportedReferenceUsage */
                                            $current = &$parent;
                                        }
                                    }
                                    if ($current instanceof Dictionary) {
                                        if ($propertyDescription instanceof ExpressionDescription) {
                                            $value = ManagedObject::coercedValue($value, $propertyDescription->resultType, isOptional: $propertyDescription->isOptional);
                                        }
                                        $current[$key] = $value;
                                        if (!$propertyDescription instanceof CompositeAttributeDescription && $this->request->resultType !== FetchRequestResultType::dictionaryResultType) {
                                            $current["isInserted"] = true;
                                            $current["isFault"] = $this->request->returnsObjectsAsFaults;
                                        }
                                    }
                                }
                            }
                            unset($current);
                            $currentEntity = $entity;
                        }
                        if ($this->request->resultType !== FetchRequestResultType::dictionaryResultType) {
                            /** @psalm-suppress InvalidArgument */
                            $representation["isInserted"] = true;
                            $representation["isFault"] = $this->request->returnsObjectsAsFaults;
                        }
                        $map[$referenceObject] = $representation;
                    }
                } while ($execute->nextRowset() && $execute->columnCount());
                return $map->values;
            })(),
            FetchRequestResultType::countResultType => new ArrayClass([new Number((int)$execute->fetchColumn())]),
        };
        if ($this->request->resultType === FetchRequestResultType::managedObjectResultType || $this->request->resultType === FetchRequestResultType::managedObjectIDResultType) {
            /** @psalm-suppress InvalidArgument */
            $objects = fn(): ArrayClass => $values->map(function (Dictionary $dictionary): ManagedObject {
                /** @var SQLEntity $entity */
                $entity = $this->sqlModel->entitiesByName[$dictionary[$this->sqlEntityForFetchRequest->entityKey->columnName]];
                $object = $this->context->object($this->sqlCore->objectID($entity->entityDescription, $dictionary[$entity->primaryKey->columnName]));
                $object->isSuppressingKVO = true;
                $object->setValuesForKeys($dictionary);
                if (!$object->isAwakeFromFetch) {
                    $object->awakeFromFetch();
                    $object->isAwakeFromFetch = true;
                }
                $object->isSuppressingKVO = false;
                return $object->serialized($this->request->serialization);
            });
            if ($this->request->includesPropertyValues) {
                $values = $objects();
                if ($this->request->resultType === FetchRequestResultType::managedObjectIDResultType) {
                    $values = $values->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID);
                }
            } else {
                /** @psalm-suppress InvalidArgument */
                $values = $this->request->resultType === FetchRequestResultType::managedObjectResultType ? $objects() : $values->map(fn(Dictionary $dictionary): ManagedObjectID => $this->sqlCore->objectID($this->sqlEntityForFetchRequest->entityDescription, $dictionary[$this->sqlEntityForFetchRequest->primaryKey->columnName]));
            }
        }
        $this->result = $values;
        if ($this->debugLogLevel) {
            $message = sprintf("CoreData: annotation: total execution time: %s for %s element(s)", human_readable_time(absolute_time_get_current() - $time), $this->result->count);
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
