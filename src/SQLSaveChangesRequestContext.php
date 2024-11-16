<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Number;

/** @internal */
class SQLSaveChangesRequestContext extends SQLStoreRequestContext
{
    public readonly SQLSavePlan $savePlan;
    public SQLModel $sqlModel {
        get => $this->sqlCore->model;
    }

    public function __construct(public readonly SaveChangesRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->savePlan = new SQLSavePlan($this);
        $this->isWritingRequest = true;
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        if (!($statement = $this->generator->statement)) {
            return false;
        }
        $this->connection->execute($statement);
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
