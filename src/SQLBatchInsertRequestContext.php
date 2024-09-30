<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Number;

/** @internal */
class SQLBatchInsertRequestContext extends SQLStoreRequestContext
{
    public function __construct(public readonly BatchInsertRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->isWritingRequest = true;
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
