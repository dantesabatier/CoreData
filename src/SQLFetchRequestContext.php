<?php

namespace Sabatier\CoreData;

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
    public readonly SQLModel $sqlModel;
    public readonly SQLEntity $sqlEntityForFetchRequest;
    public readonly SQLStatement $fetchStatement;

    public function __construct(public readonly FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->sqlModel = $this->sqlCore->model;
        $this->sqlEntityForFetchRequest = $this->sqlModel->entity($this->request->entity->name) ?? fatal_error("Entity \"{$this->request->entity->name}\" does not exists");
        $this->fetchStatement = $this->generator->statement ?? fatal_error();
    }

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
                            if ($keys[$keys->indexBefore($keys->endIndex())] === $currentEntity->primaryKey->columnName) {
                                $copy = clone $keys;
                                $copy->removeAt($copy->indexBefore($copy->endIndex()));
                                $keyPath = $copy->join(".");
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
                            foreach ($keys as $key) {
                                $property = $currentEntity->propertiesByName[$key];
                                $propertyDescription = $property?->propertyDescription ?? $this->request->propertiesToFetch?->first(fn(string|PropertyDescription $property): bool => $property instanceof PropertyDescription ? $property->name === $key : $property === $key);
                                if ($property instanceof SQLRelationship) {
                                    if ($current instanceof ArrayClass && !$current->isEmpty) {
                                        /** @psalm-suppress UnsupportedReferenceUsage */
                                        $current = &$current[$current->indexBefore($current->endIndex())];
                                    }
                                    if ($current instanceof Dictionary) {
                                        $current[$key] ??= $property instanceof SQLToOne ? new Dictionary() : new ArrayClass();
                                        $current = &$current[$key];
                                    }
                                    $relationship = $property;
                                    $currentEntity = $relationship->destinationEntity;
                                }
                                if ($property instanceof SQLColumn || $propertyDescription instanceof ExpressionDescription) {
                                    if ($current instanceof ArrayClass) {
                                        if ($property instanceof SQLPrimaryKey && !$value instanceof Nil && !$current->contains(fn(Dictionary $dictionary): bool => $dictionary[$key] === $value)) {
                                            $current[] = new Dictionary([$currentEntity->primaryKey->columnName => $value]);
                                        }
                                        if (!$current->isEmpty) {
                                            /** @psalm-suppress UnsupportedReferenceUsage */
                                            $current = &$current[$current->indexBefore($current->endIndex())];
                                        }
                                    }
                                    if ($current instanceof Dictionary) {
                                        if ($propertyDescription instanceof ExpressionDescription) {
                                            $value = ManagedObject::coercedValue($value, $propertyDescription->resultType, isOptional: $propertyDescription->isOptional);
                                        }
                                        $current[$key] = $value;
                                        if ($this->request->resultType !== FetchRequestResultType::dictionaryResultType) {
                                            $current["isInserted"] = true;
                                        }
                                    }
                                }
                            }
                            $currentEntity = $entity;
                            unset($current);
                        }
                        if ($this->request->resultType !== FetchRequestResultType::dictionaryResultType) {
                            /** @psalm-suppress InvalidArgument */
                            $representation["isInserted"] = true;
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
                $object->isFault = $this->request->returnsObjectsAsFaults;
                $object->setValuesForKeys($dictionary);
                if (!$object->isAwakening) {
                    $object->isAwakening = true;
                    $object->awakeFromFetch();
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
