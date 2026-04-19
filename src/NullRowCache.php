<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * A no-op implementation of PersistentStoreCache.
 *
 * This cache does not store or return any data. All operations are effectively
 * ignored, and cache lookups always result in a miss.
 *
 * Characteristics:
 * - Zero overhead
 * - Useful for disabling caching explicitly
 *
 * Recommended for:
 * - Testing
 * - Debugging cache-related behavior
 * - Environments where caching is not desired
 */
final class NullRowCache implements PersistentStoreCache
{
    #[Override]
    public function currentGenerationForStore(string $storeIdentifier): int
    {
        return 1;
    }

    #[Override]
    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        return 1;
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): bool
    {
        return false;
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): ?Dictionary
    {
        return null;
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600, ?PropertyDescription $property = null): void
    {
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): void
    {
    }

    #[Override]
    public function snapshots(ArrayClass $objectIDs): Dictionary
    {
        return new Dictionary();
    }

    #[Override]
    public function setSnapshots(Dictionary $snapshots, int $ttl = 3600): void
    {
    }

    #[Override]
    public function deleteSnapshots(ArrayClass $objectIDs): void
    {
    }

    #[Override]
    public function queryKeyForRequest(FetchRequest $request, QueryGenerationToken $token): string
    {
        return "";
    }
}
