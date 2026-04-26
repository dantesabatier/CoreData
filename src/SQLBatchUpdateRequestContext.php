<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/** @internal */
final class SQLBatchUpdateRequestContext extends SQLBatchOperationRequestContext
{
    public BatchUpdateRequest $request {
        get {
            /** @var BatchUpdateRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    private(set) SQLFetchRequestContext $fetchContext {
        get {
            if (isset($this->fetchContext)) {
                return $this->fetchContext;
            }
            /** @var FetchRequest<ManagedObjectID> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $this->request->entity;
            $fetchRequest->predicate = $this->request->predicate;
            $fetchRequest->propertiesToFetch = $this->request->propertiesToUpdate->keys;
            $fetchRequest->includesSubentities = $this->request->includesSubentities;
            $fetchRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
            return $this->fetchContext = new SQLFetchRequestContext($fetchRequest, $this->context, $this->sqlCore);
        }
    }
    private(set) ?SQLStatement $updateStatement {
        get => $this->updateStatement ??= $this->generator->statement;
    }

    public function __construct(BatchUpdateRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
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
                    $managedObjectIDs->append($this->sqlCore->objectID($entity->entityDescription, $data[$entity->primaryKey->columnName]));
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
