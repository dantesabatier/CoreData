<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Number;

/** @internal */
final class SQLSaveChangesRequestContext extends SQLStoreRequestContext
{
    public SaveChangesRequest $request {
        get {
            /** @var SaveChangesRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    public bool $isWritingRequest {
        get => true;
    }

    public function __construct(SaveChangesRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        if (!($statement = $this->generator->statement)) {
            return false;
        }
        $this->connection->execute($statement);
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }
}
