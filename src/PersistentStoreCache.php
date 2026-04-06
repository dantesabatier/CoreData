<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/**
 * A set of methods that a persistent store cache must implement to provide L2 row caching.
 * The cache stores raw snapshots of managed objects to improve performance by reducing database hits.
 */
interface PersistentStoreCache
{
    /**
     * Returns the raw snapshot for the specified managed object ID.
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @return Dictionary|null The raw snapshot of the managed object, or null if it is not in the cache.
     */
    public function snapshotForKey(ManagedObjectID $objectID): ?Dictionary;

    /**
     * Saves a raw snapshot for the specified managed object ID in the cache.
     * @param Dictionary $snapshot The raw snapshot of the managed object to cache.
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param int $ttl The time-to-live for the cached snapshot, in seconds. Default is 3600.
     */
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600): void;

    /**
     * Removes the snapshot for the specified managed object ID from the cache.
     * @param ManagedObjectID $objectID The object ID of the managed object.
     */
    public function deleteSnapshot(ManagedObjectID $objectID): void;
}
