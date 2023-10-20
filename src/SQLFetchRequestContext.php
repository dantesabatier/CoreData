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
        $this->fetchStatement = $this->generator->statement() ?? fatal_error();
    }

    public function executeRequestCore(): bool
    {
        $time = absolute_time_get_current();
        $execute = $this->connection->execute($this->fetchStatement);
        $resultType = $this->request->resultType;
        /** @var ArrayClass<Dictionary<mixed>|Number> $values */
        $values = match ($resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType, FetchRequestResultType::dictionaryResultType => (function () use ($resultType, $execute): ArrayClass {
                /** @var Dictionary<Dictionary<mixed>> $map */
                $map = new Dictionary();
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
                        if ($resultType !== FetchRequestResultType::dictionaryResultType) {
                            $representation["isInserted"] = true;
                        }
                        foreach ($data as $pattern => $value) {
                            $value ??= Nil::nil();
                            $keys = new ArrayClass(explode("_", $pattern));
                            if ($keys->count() < 3) {
                                $property = $currentEntity->propertiesByName[$pattern];
                                if ($property instanceof SQLProperty) {
                                    $propertyDescription = $property->propertyDescription;
                                    if ($propertyDescription instanceof ExpressionDescription) {
                                        $value = ManagedObject::coercedValue($value, $propertyDescription->expressionResultType, isOptional: false);
                                    } elseif ($propertyDescription instanceof AttributeDescription) {
                                        ManagedObject::coerceValue($value, $propertyDescription);
                                    }
                                }
                                /** @psalm-suppress PossiblyNullReference */
                                $representation[$pattern] = $value;
                                continue;
                            }
                            $relationship = null;
                            $current = &$representation;
                            $keys->removeAt(0);
                            foreach ($keys as $key) {
                                $property = $currentEntity->propertiesByName[$key];
                                if ($property instanceof SQLRelationship) {
                                    if ($value instanceof Nil) {
                                        continue;
                                    }
                                    if ($current instanceof Set && !$current->isEmpty()) {
                                        /** @psalm-suppress UnsupportedReferenceUsage */
                                        $current = &$current[$current->indexBefore($current->endIndex())];
                                    }
                                    if ($current instanceof Dictionary) {
                                        $current[$key] ??= $property instanceof SQLToOne ? new Dictionary() : new Set();
                                        $current = &$current[$key];
                                    }
                                    $relationship = $property;
                                    $currentEntity = $relationship->destinationEntity;
                                }
                                if ($property instanceof SQLColumn) {
                                    if ($current instanceof Set) {
                                        $cached = $current;
                                        if ($property instanceof SQLPrimaryKey && !$cached->contains(fn(Dictionary $dictionary): bool => $dictionary[$key] === $value)) {
                                            $cached[] = new Dictionary();
                                        }
                                        if (!$current->isEmpty()) {
                                            /** @psalm-suppress UnsupportedReferenceUsage */
                                            $current = &$cached[$cached->indexBefore($cached->endIndex())];
                                        }
                                    }
                                    if ($current instanceof Dictionary) {
                                        if ($resultType !== FetchRequestResultType::dictionaryResultType) {
                                            $current["isInserted"] = true;
                                        }
                                        $current[$key] ??= $value;
                                    }
                                    $currentEntity = $entity;
                                }
                            }
                        }
                        $map[$referenceObject] = $representation;
                    }
                } while ($execute->nextRowset() && $execute->columnCount());
                return $map->values;
            })(),
            FetchRequestResultType::countResultType => new ArrayClass([new Number((int)$execute->fetchColumn())]),
        };
        /** @var SQLEntity $entity */
        $entity = $this->sqlModel->entitiesByName[$this->request->entity->name];
        if ($resultType === FetchRequestResultType::managedObjectResultType || $resultType === FetchRequestResultType::managedObjectIDResultType) {
            /** @psalm-suppress InvalidArgument */
            $objects = fn(): ArrayClass => $values->map(function (Dictionary $dictionary) use ($entity): ManagedObject {
                /** @var SQLEntity $entity */
                $entity = $this->sqlModel->entitiesByName[$dictionary[$entity->entityKey->name]];
                $object = $this->context->object($this->sqlCore->objectID($entity->entityDescription, $dictionary[$entity->primaryKey->columnName]));
                $object->isSuppressingKVO = true;
                $object->isFault = $this->request->returnsObjectsAsFaults;
                $object->setValuesForKeys($dictionary);
                $object->awakeFromFetch();
                $object->isSuppressingKVO = false;
                return $object->serialized($this->request->serialization);
            });
            if ($this->request->includesPropertyValues) {
                $values = $objects();
                if ($resultType === FetchRequestResultType::managedObjectIDResultType) {
                    $values = $values->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID);
                }
            } else {
                /** @psalm-suppress InvalidArgument */
                $values = $resultType === FetchRequestResultType::managedObjectResultType ? $objects() : $values->map(fn(Dictionary $dictionary): ManagedObjectID => $this->sqlCore->objectID($entity->entityDescription, $dictionary[$entity->primaryKey->columnName]));
            }
        }
        $this->result = $values;
        if ($this->debugLogLevel) {
            $message = sprintf("CoreData: annotation: total execution time: %s for %s element(s)", human_readable_time(absolute_time_get_current() - $time), $this->result->count());
            if ($this->debugLogLevel > 3) {
                $message .= " $this->result";
            }
            error_log($message);
        }
        return true;
    }
}
