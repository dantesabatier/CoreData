<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;
use function Sabatier\Foundation\fatal_error;

/** @internal */
class SQLBatchInsertRequestContext extends SQLBatchOperationRequestContext
{
    public BatchInsertRequest $request {
        get {
            /** @var BatchInsertRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    private(set) SQLEntity $sqlEntityForInsertRequest {
        get => $this->sqlEntityForInsertRequest ??= $this->sqlModel->entity($this->request->entity->name) ?? fatal_error("Entity \"{$this->request->entity->name}\" does not exists");
    }
    public ?SQLStatement $insertStatement {
        get => $this->insertStatement ??= $this->generator->statement;
    }
    public bool $isWritingRequest {
        get => true;
    }

    public function __construct(BatchInsertRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
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
        /** @var ArrayClass<ManagedObjectID> $affectedObjectIDs */
        $affectedObjectIDs = $this->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey) ? ($this->request->resultType === BatchInsertRequestResultType::objectIDs ? $this->result : $objectIDs()) : new ArrayClass();
        $this->affectedObjectIDs = $affectedObjectIDs;
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
