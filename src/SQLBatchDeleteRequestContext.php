<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/** @internal */
class SQLBatchDeleteRequestContext extends SQLStoreRequestContext
{
    /** @var FetchRequest<ManagedObjectID> */
    public readonly FetchRequest $fetchRequestForObjectsToDelete;
    /** @var ArrayClass<ManagedObjectID> */
    public readonly ArrayClass $affectedObjectIDs;
    public readonly SQLFetchRequestContext $fetchContext;
    public readonly ?SQLStatement $deleteStatement;

    public function __construct(public readonly BatchDeleteRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->fetchRequestForObjectsToDelete = clone $this->request->fetchRequest;
        $this->fetchRequestForObjectsToDelete->resultType = FetchRequestResultType::managedObjectIDResultType;
        $this->fetchRequestForObjectsToDelete->includesPropertyValues = false;
        $this->fetchContext = new SQLFetchRequestContext($this->fetchRequestForObjectsToDelete, $this->context, $this->sqlCore);
        $this->deleteStatement = $this->generator->statement();
        $this->isWritingRequest = true;
    }

    public function executeRequestCore(): bool
    {
        if (!($deleteStatement = $this->deleteStatement)) {
            return false;
        }
        $execute = $this->connection->execute($this->fetchContext->fetchStatement);
        /** @return ArrayClass<ManagedObjectID> */
        $objectIDs = function () use ($execute): ArrayClass {
            /** @var SQLEntity $entity */
            $entity = $this->sqlCore->model->entity($this->fetchRequestForObjectsToDelete->entity->name);
            /** @var ArrayClass<ManagedObjectID> $managedObjectIDs */
            $managedObjectIDs = new ArrayClass();
            do {
                while ($data = $execute->fetch()) {
                    $managedObjectIDs->append($this->sqlCore->newObjectID($entity->entityDescription, $data[$entity->primaryKey->columnName]));
                }
            } while ($execute->nextRowset() && $execute->columnCount());
            return $managedObjectIDs;
        };
        $this->result = match ($this->request->resultType) {
            BatchDeleteRequestResultType::statusOnly => new ArrayClass([new Number(true)]),
            BatchDeleteRequestResultType::objectIDs => $objectIDs(),
            BatchDeleteRequestResultType::count => new ArrayClass([new Number($execute->rowCount())]),
        };
        /** @psalm-suppress PossiblyInvalidPropertyAssignmentValue */
        $this->affectedObjectIDs = $this->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey) ? ($this->request->resultType === BatchDeleteRequestResultType::objectIDs ? $this->result : $objectIDs()) : new ArrayClass();
        $this->connection->execute($deleteStatement);
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
