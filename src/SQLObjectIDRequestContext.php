<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class SQLObjectIDRequestContext extends SQLStoreRequestContext
{
    public bool $isWritingRequest {
        get => true;
    }

    public function __construct(public readonly Dictionary $entitiesAndCounts, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct(new SaveChangesRequest(), $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        return true;
    }
}
