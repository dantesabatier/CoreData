<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
readonly class StandardHydrationStrategy extends HydrationStrategy
{
    #[Override]
    protected function resolveStoreSpecificAttributes(ManagedObject $object, Dictionary $representation, Dictionary $snapshot): void
    {
    }
}
