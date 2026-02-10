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
        if (!$baseline->offsetExists(ManagedObjectVersionKey)) {
            return false;
        }
        if (!$store->offsetExists(ManagedObjectVersionKey)) {
            return false;
        }
        return $store[ManagedObjectVersionKey] !== $baseline[ManagedObjectVersionKey];
    }
}

