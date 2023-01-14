<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/** @internal */
class SQLBatchUpdateRequestContext extends SQLStoreRequestContext
{
    public readonly SQLFetchRequestContext $fetchContext;
    public readonly ?SQLStatement $updateStatement;
    /** @var ArrayClass<ManagedObjectID> */
    public readonly ArrayClass $affectedObjectIDs;

    public function __construct(public readonly BatchUpdateRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->fetchContext = $this->createFetchRequestContextForObjectsToUpdate();
        $this->updateStatement = $this->generator->statement();
        $this->isWritingRequest = true;
    }

    private function createFetchRequestContextForObjectsToUpdate(): SQLFetchRequestContext
    {
        return new SQLFetchRequestContext($this->fetchRequestDescribingObjectsToUpdate(), $this->context, $this->sqlCore);
    }

    private function fetchRequestDescribingObjectsToUpdate(): FetchRequest
    {
        /** @var FetchRequest<ManagedObjectID> $fetchRequest */
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $this->request->entity;
        $fetchRequest->predicate = $this->request->predicate;
        $fetchRequest->includesSubentities = $this->request->includesSubentities;
        $fetchRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
        $fetchRequest->includesPropertyValues = false;
        return $fetchRequest;
    }

    public function executeRequestCore(): bool
    {
        if (!($updateStatement = $this->updateStatement)) {
            return false;
        }
        $statement = SQLStatement::merging(new ArrayClass([new SQLStatement("{$this->fetchContext->fetchStatement->string} FOR UPDATE", $this->fetchContext->fetchStatement->arguments), $updateStatement]));
        $execute = $this->connection->execute($statement);
        /** @return ArrayClass<ManagedObjectID> */
        $objectIDs = function () use ($execute): ArrayClass {
            /** @var SQLEntity $entity */
            $entity = $this->sqlCore->model->entity($this->request->entity->name);
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
            BatchUpdateRequestResultType::statusOnly => new ArrayClass([new Number(true)]),
            BatchUpdateRequestResultType::objectIDs => $objectIDs(),
            BatchUpdateRequestResultType::count => new ArrayClass([new Number($execute->rowCount())]),
        };
        /** @psalm-suppress PossiblyInvalidPropertyAssignmentValue */
        $this->affectedObjectIDs = $this->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey) ? ($this->request->resultType === BatchUpdateRequestResultType::objectIDs ? $this->result : $objectIDs()) : new ArrayClass();
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
