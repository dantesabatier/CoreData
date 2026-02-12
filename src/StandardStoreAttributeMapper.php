<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

final readonly class StandardStoreAttributeMapper implements StoreAttributeMapper
{
	#[Override]
    public function map(ManagedObject $object, Dictionary $mappedValues, Dictionary $snapshot): void
    {
    }
}
