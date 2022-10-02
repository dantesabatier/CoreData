<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
class SQLObjectIDRequestContext extends SQLStoreRequestContext
{
    public readonly SQLModel $model;

    public function __construct(public readonly Dictionary $entitiesAndCounts, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct(new SaveChangesRequest(), $context, $sqlCore);
        $this->isWritingRequest = true;
    }

    public function executeRequestCore(): bool
    {
        return true;
    }
}
