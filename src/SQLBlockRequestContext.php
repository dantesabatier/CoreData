<?php

namespace Sabatier\CoreData;

use Closure;
use Override;

/** @internal */
class SQLBlockRequestContext extends SQLStoreRequestContext
{
    public readonly Closure $block;

    public function __construct(Closure $block, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct(new SaveChangesRequest(), $context, $sqlCore);
        $this->block = $block;
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        return true;
    }
}
