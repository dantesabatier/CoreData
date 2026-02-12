<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class StandardStoreMetadataPruner implements StoreMetadataPruner
{
    #[Override]
    public function prune(Dictionary $snapshot): void
    {
    }
}
