<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final readonly class StandardSnapshotMappingStrategy extends SnapshotMappingStrategy
{
    #[Override]
    protected function resolveStoreSpecificAttributes(ManagedObject $object, Dictionary $mappedValues, Dictionary $snapshot): void
    {
    }
}
