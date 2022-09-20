<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Number;

/** @internal */
class SQLSaveChangesRequestContext extends SQLStoreRequestContext
{
    public readonly SQLSavePlan $savePlan;
    public readonly SQLModel $sqlModel;

    public function __construct(public readonly SaveChangesRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->savePlan = new SQLSavePlan($this);
        $this->sqlModel = $this->sqlCore->model;
        $this->isWritingRequest = true;
    }

    public function executeRequestCore(): bool
    {
        if ($statement = $this->generator->statement()) {
            $this->connection->execute($statement);
            $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
            return true;
        }
        return false;
    }
}
