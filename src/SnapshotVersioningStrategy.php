<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class SnapshotVersioningStrategy implements VersioningStrategy
{
    /**
     * @param Dictionary<mixed> $baseline
     * @param Dictionary<mixed> $store
     * @return bool
     */
    public function hasConflict(Dictionary $baseline, Dictionary $store): bool
    {
        return $store[ManagedObjectVersionKey] !== $baseline[ManagedObjectVersionKey];
    }
}

