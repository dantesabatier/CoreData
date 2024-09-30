<?php

namespace Sabatier\CoreData;

use Closure;
use Override;

/** @internal */
class SQLBlockRequestContext extends SQLStoreRequestContext
{
    public function __construct(public readonly Closure $block, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct(new SaveChangesRequest(), $context, $sqlCore);
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        return true;
    }
}
