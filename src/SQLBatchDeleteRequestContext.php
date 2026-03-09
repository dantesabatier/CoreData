<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SQLBatchDeleteRequestContext extends SQLBatchOperationRequestContext
{
    public BatchDeleteRequest $request {
        get {
            /** @var BatchDeleteRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    /** @var FetchRequest<ManagedObjectID> */
    private(set) FetchRequest $fetchRequestForObjectsToDelete {
        get {
            if (!isset($this->fetchRequestForObjectsToDelete)) {
                $fetchRequestForObjectsToDelete = clone($this->request->fetchRequest, [
                    "resultType" => FetchRequestResultType::managedObjectIDResultType,
                    "includesPropertyValues" => false
                ]);
                $this->fetchRequestForObjectsToDelete = $fetchRequestForObjectsToDelete;
            }
            return $this->fetchRequestForObjectsToDelete;
        }
    }
    private(set) SQLFetchRequestContext $fetchContext {
        get => $this->fetchContext ??= new SQLFetchRequestContext($this->fetchRequestForObjectsToDelete, $this->context, $this->sqlCore);
    }
    private(set) ?SQLStatement $deleteStatement {
        get => $this->deleteStatement ??= $this->generator->statement;
    }
    #[Override]
    public bool $isWritingRequest {
        get => true;
    }

    public function __construct(BatchDeleteRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        if (!($deleteStatement = $this->deleteStatement)) {
            return false;
        }
        $execute = $this->connection->execute($this->fetchContext->fetchStatement);
        /** @return ArrayClass<ManagedObjectID> */
        $objectIDs = function () use ($execute): ArrayClass {
            /** @var SQLEntity $entity */
            $entity = $this->sqlCore->model->entity($this->fetchRequestForObjectsToDelete->entity?->name ?? fatal_error("Invalid fetch request: missing entity"));
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
