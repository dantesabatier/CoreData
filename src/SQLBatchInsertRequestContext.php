<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;
use function Sabatier\Foundation\fatal_error;

/** @internal */
class SQLBatchInsertRequestContext extends SQLStoreRequestContext
{
    public BatchInsertRequest $request {
        get {
            /** @var BatchInsertRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    public SQLEntity $entity {
        get => $this->sqlCore->model->entitiesByName[$this->request->entity->name] ?? fatal_error();
    }
    /** @var ArrayClass<Dictionary|ManagedObject>|null */
    public ?ArrayClass $objectsToInsert {
        get {
            if ($objectsToInsert = $this->request->objectsToInsert) {
                return $objectsToInsert;
            }
            $entity = $this->entity;
            if ($dictionaryHandler = $this->request->dictionaryHandler) {
                /** @var ArrayClass<ManagedObject> $managedObjects */
                $managedObjects = new ArrayClass();
                while (true) {
                    $keyedValues = new Dictionary();
                    $ok = $dictionaryHandler($keyedValues);
                    $managedObject = EntityDescription::insertNewObject($entity->tableName, $this->context);
                    $managedObject->setValuesForKeys($keyedValues);
                    if (!$ok) {
                        break;
                    }
                    $managedObjects->append($managedObject);
                }
                return $managedObjects;
            }
            if ($managedObjectHandler = $this->request->managedObjectHandler) {
                /** @var ArrayClass<ManagedObject> $managedObjects */
                $managedObjects = new ArrayClass();
                while (true) {
                    $managedObject = EntityDescription::insertNewObject($entity->tableName, $this->context);
                    if (!$managedObjectHandler($managedObject)) {
                        break;
                    }
                    $managedObjects->append($managedObject);
                }
                return $managedObjects;
            }
            return null;
        }
    }
    public ?SQLStatement $insertStatement {
        get {
            if (!($array = $this->objectsToInsert)) {
                return null;
            }
            $entity = $this->entity;
            /** @var ArrayClass<string> $columnNames */
            $columnNames = new ArrayClass();
            $columnNames->appendContentsOf([$entity->entityKey->columnName]);
            $element = $array->first ?? fatal_error();
            if ($element instanceof ManagedObject) {
                $columnNames->appendContentsOf($element->changedValuesForCurrentEvent()->keys);
            }
            $columns = $entity->columnsToCreate->filter(fn(SQLColumn $column): bool => $columnNames->containsElement($column->columnName));
            $string = "INSERT INTO `$entity->tableName` ({$columns->map(fn(SQLColumn $column): string => "`$column->columnName`")->join(", ")}) VALUES " . ArrayClass::repeating("(" . ArrayClass::repeating("?", $columns->count)->join(", ") . ")", $array->count)->join(", ") . " RETURNING `{$entity->primaryKey->columnName}`";
            $arguments = $array->flatMap(fn(ManagedObject|Dictionary $object): ArrayClass => $columns->map(function (SQLColumn $column) use ($entity, $object): mixed {
                if ($column instanceof SQLEntityKey) {
                    return $entity->tableName;
                }
                if ($column instanceof SQLAttribute) {
                    $value = $object->valueForKey($column->name);
                    ManagedObject::coerceValue($value, $column->attributeDescription, true);
                    return $value;
                }
                return $object->valueForKey($column->name);
            }));
            return new SQLStatement($string, $arguments);
        }
    }
    /** @var ArrayClass<ManagedObjectID> */
    public ArrayClass $affectedObjectIDs;

    public function __construct(BatchInsertRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
        $this->isWritingRequest = true;
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        if (!($insertStatement = $this->insertStatement)) {
            return false;
        }
        $execute = $this->connection->execute($insertStatement);
        /** @return ArrayClass<ManagedObjectID> */
        $objectIDs = function () use ($execute): ArrayClass {
            /** @var SQLEntity $entity */
            $entity = $this->sqlCore->model->entitiesByName[$this->request->entity->name];
            /** @var ArrayClass<ManagedObjectID> $managedObjectIDs */
            $managedObjectIDs = new ArrayClass();
            do {
                while ($data = $execute->fetch()) {
                    $managedObjectIDs->append($this->sqlCore->objectID($entity->entityDescription, $data[$entity->primaryKey->columnName]));
                }
            } while ($execute->nextRowset() && $execute->columnCount());
            return $managedObjectIDs;
        };
        $this->result = match ($this->request->resultType) {
            BatchInsertRequestResultType::statusOnly => new ArrayClass([new Number(true)]),
            BatchInsertRequestResultType::objectIDs => $objectIDs(),
            BatchInsertRequestResultType::count => new ArrayClass([new Number($execute->rowCount())]),
        };
        $this->affectedObjectIDs = $this->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey) ? ($this->request->resultType === BatchInsertRequestResultType::objectIDs ? $this->result : $objectIDs()) : new ArrayClass();
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
