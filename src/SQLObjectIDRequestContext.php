<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
class SQLObjectIDRequestContext extends SQLStoreRequestContext
{
    public readonly Dictionary $entitiesAndCounts;

    public function __construct(Dictionary $entitiesAndCounts, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct(new SaveChangesRequest(), $context, $sqlCore);
        $this->entitiesAndCounts = $entitiesAndCounts;
        $this->isWritingRequest = true;
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        return true;
    }
}
