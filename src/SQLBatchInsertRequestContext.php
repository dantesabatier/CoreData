<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Number;

/** @internal */
class SQLBatchInsertRequestContext extends SQLStoreRequestContext
{
    public function __construct(public readonly BatchInsertRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->isWritingRequest = true;
    }

    public function executeRequestCore(): bool
    {
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
