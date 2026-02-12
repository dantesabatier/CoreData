<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final readonly class StandardStoreAttributeMapper extends StoreAttributeMapper
{
    #[Override]
    public function map(ManagedObject $object, Dictionary $mappedValues, Dictionary $snapshot): void
    {
    }
}
