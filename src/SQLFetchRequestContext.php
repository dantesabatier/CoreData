<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\absolute_time_get_current;
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
        $this->sqlEntityForFetchRequest = $this->sqlModel->entity($this->request->entity->name) ?? throw new InternalInconsistencyException("Entity \"{$this->request->entity->name}\" does not exists");
        $this->fetchStatement = $this->generator->statement() ?? throw new InternalInconsistencyException();
    }

    public function executeRequestCore(): bool
    {
        $time = absolute_time_get_current();
        $execute = $this->connection->execute($this->fetchStatement);
        $resultType = $this->request->resultType;
        /** @var ArrayClass<Dictionary|Number> $values */
        $values = match ($resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType, FetchRequestResultType::dictionaryResultType => (function () use ($execute): ArrayClass {
                /** @var Dictionary<Dictionary<mixed>> $map */
                $map = new Dictionary();
                do {
                    /** @var array<string, mixed> $data */
                    while ($data = $execute->fetch()) {
                        $entityName = $data['entityName'] ?? $this->request->entity->name;
                        /** @var SQLEntity $entity */
                        $entity = $this->sqlModel->entitiesByName[$entityName];
                        $currentEntity = $entity;
                        $referenceObject = (string)$data[$entity->primaryKey->columnName];
                        /** @var Dictionary<mixed> $representation */
                        $representation = $map[$referenceObject] ?? new Dictionary();
                        foreach ($data as $key => $value) {
                            if ($value === null) {
                                continue;
                            }
                            $keys = new ArrayClass(explode("_", $key));
                            if ($keys->count() < 3) {
                                $property = $currentEntity->propertiesByName[$key];
                                if ($property instanceof SQLProperty) {
                                    $propertyDescription = $property->propertyDescription;
                                    if ($propertyDescription instanceof ExpressionDescription) {
                                        $value = ManagedObject::coercedValue($value, $propertyDescription->expressionResultType);
                                    } elseif ($propertyDescription instanceof AttributeDescription) {
                                        ManagedObject::coerceValue($value, $propertyDescription);
                                    }
                                }
                                $representation[$key] = $value;
                                continue;
                            }
                            $relationship = null;
                            $current = &$representation;
                            $keys->removeAt(0);
                            foreach ($keys as $key) {
                                $property = $currentEntity->propertiesByName[$key];
                                if ($property instanceof SQLRelationship) {
                                    if ($current instanceof Set && !$current->isEmpty()) {
                                        $current = &$current[$current->indexBefore($current->endIndex())];
                                    }
                                    if ($current instanceof Dictionary) {
                                        if ($current[$key] === null) {
                                            if ($property instanceof SQLToOne) {
                                                $current[$key] = new Dictionary();
                                            } else {
                                                $current[$key] = new Set();
                                            }
                                        }
                                        $current = &$current[$key];
                                    }
                                    $relationship = $property;
                                    $currentEntity = $relationship->destinationEntity;
                                }
                                if ($property instanceof SQLColumn) {
                                    if (($relationship instanceof SQLToMany || $relationship instanceof SQLManyToMany) && $current instanceof Set) {
                                        $cached = $current;
                                        if ($property instanceof SQLPrimaryKey && !$cached->contains(fn(Dictionary $dictionary): bool => $dictionary[$property->name] === $value)) {
                                            $cached[] = new Dictionary();
                                        }
                                        if (!$cached->isEmpty()) {
                                            $current = &$cached[$cached->indexBefore($cached->endIndex())];
                                        }
                                    }
                                    if ($current instanceof Dictionary && $current[$key] === null) {
                                        $current[$key] = $value;
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
                $object = $this->context->object($this->sqlCore->newObjectID($entity->entityDescription, $dictionary[$entity->primaryKey->columnName]));
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
                $values = $resultType === FetchRequestResultType::managedObjectResultType ? $objects() : $values->map(fn(Dictionary $dictionary): ManagedObjectID => $this->sqlCore->newObjectID($entity->entityDescription, $dictionary[$entity->primaryKey->columnName]));
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
