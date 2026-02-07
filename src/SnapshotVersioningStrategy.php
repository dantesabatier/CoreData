<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class SnapshotVersioningStrategy implements VersioningStrategy
{
    /**
     * @param Dictionary<mixed> $baseline
     * @param Dictionary<mixed> $store
     * @return bool
     */
    #[Override]
    public function hasConflict(Dictionary $baseline, Dictionary $store): bool
    {
        return $store[ManagedObjectVersionKey] !== $baseline[ManagedObjectVersionKey];
    }
}

