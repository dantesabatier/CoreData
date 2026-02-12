<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

final readonly class SQLStoreMetadataPruner implements StoreMetadataPruner
{
	#[Override]
    public function prune(Dictionary $snapshot): void
    {
        $snapshot->removeValueForKey(ManagedObjectParentIDKey);
    }
}
