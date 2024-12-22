<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Number;

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

    public function __construct(BatchInsertRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
        $this->isWritingRequest = true;
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
