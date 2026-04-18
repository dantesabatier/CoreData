<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * Default in-memory implementation of RowCache.
 *
 * Stores snapshots and query results in process memory. This implementation
 * is useful for development, testing, or single-process environments where
 * persistence across requests is not required.
 *
 * Characteristics:
 * - Fast access (no I/O)
 * - Not shared between processes
 * - Resets on every request lifecycle
 */
final class DefaultRowCache extends RowCache
{
    /** @var Dictionary<mixed> */
    private Dictionary $storage;

    public function __construct()
    {
        $this->storage = new Dictionary();
    }

    #[Override]
    public function currentGenerationForStore(string $storeIdentifier): int
    {
        return $this->storage["generation:$storeIdentifier"] ?? 1;
    }

    #[Override]
    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        return $this->storage["generation:$storeIdentifier"] = $this->currentGenerationForStore($storeIdentifier) + 1;
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): bool
    {
        return $this->storage->offsetExists($this->cacheKey($objectID, $relationship));
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): ?Dictionary
    {
        return $this->storage[$this->cacheKey($objectID, $relationship)];
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600, ?RelationshipDescription $relationship = null): void
    {
        $this->storage[$this->cacheKey($objectID, $relationship)] = $snapshot;
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void
    {
        $this->storage->removeValueForKey($this->cacheKey($objectID, $relationship));
    }

    #[Override]
    public function snapshots(ArrayClass $objectIDs): Dictionary
    {
        return $objectIDs->reduce(new Dictionary(), function (Dictionary $result, ManagedObjectID $objectID): Dictionary {
            $key = $this->cacheKey($objectID);
            $result[$key] = $this->storage[$key];
            return $result;
        });
    }

    #[Override]
    public function setSnapshots(Dictionary $snapshots, int $ttl = SecondsPerHourTimeInterval): void
    {
        $snapshots->forEach(fn(Dictionary $snapshot, string $key) => $this->storage[$key] = $snapshot);
    }

    #[Override]
    public function deleteSnapshots(ArrayClass $objectIDs): void
    {
        $objectIDs->forEach(fn(ManagedObjectID $objectID): mixed => $this->storage->removeValueForKey($this->cacheKey($objectID)));
    }
}
