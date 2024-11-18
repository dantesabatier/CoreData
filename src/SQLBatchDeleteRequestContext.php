<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/** @internal */
class SQLBatchDeleteRequestContext extends SQLStoreRequestContext
{
    public bool $isWritingRequest = true;
    /** @var FetchRequest<ManagedObjectID> */
    private(set) FetchRequest $fetchRequestForObjectsToDelete {
        get => $this->fetchRequestForObjectsToDelete ??= $this->fetchRequestForObjectsToDelete();
    }
    /** @var ArrayClass<ManagedObjectID> */
    public readonly ArrayClass $affectedObjectIDs;
    private(set) SQLFetchRequestContext $fetchContext {
        get => $this->fetchContext ??= new SQLFetchRequestContext($this->fetchRequestForObjectsToDelete, $this->context, $this->sqlCore);
    }
    private(set) ?SQLStatement $deleteStatement {
        get => $this->deleteStatement ??= $this->generator->statement;
    }

    public function __construct(public readonly BatchDeleteRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
    }

    private function fetchRequestForObjectsToDelete(): FetchRequest
    {
        $fetchRequestForObjectsToDelete = clone $this->request->fetchRequest;
        $fetchRequestForObjectsToDelete->resultType = FetchRequestResultType::managedObjectIDResultType;
        $fetchRequestForObjectsToDelete->includesPropertyValues = false;
        return $fetchRequestForObjectsToDelete;
    }

    #[Override]
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
                    $managedObjectIDs->append($this->sqlCore->objectID($entity->entityDescription, $data[$entity->primaryKey->columnName]));
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
