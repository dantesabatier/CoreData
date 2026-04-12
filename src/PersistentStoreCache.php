<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/**
 * A set of methods that a persistent store cache must implement to provide L2 row caching.
 * The cache stores raw snapshots of managed objects to improve performance by reducing database hits.
 */
interface PersistentStoreCache
{
    public function hasSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): bool;

    /**
     * Returns the raw snapshot for the specified managed object ID.
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param RelationshipDescription|null $relationship
     * @return Dictionary<mixed>|null The raw snapshot of the managed object, or null if it is not in the cache.
     */
    public function snapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): ?Dictionary;

    /**
     * Saves a raw snapshot for the specified managed object ID in the cache.
     * @param Dictionary<mixed> $snapshot The raw snapshot of the managed object to cache.
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param int $ttl The time-to-live for the cached snapshot, in seconds. Default is 3600.
     * @param RelationshipDescription|null $relationship
     */
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600, ?RelationshipDescription $relationship = null): void;

    /**
     * Removes the snapshot for the specified managed object ID from the cache.
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param RelationshipDescription|null $relationship
     */
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void;

    /**
     * Returns the canonical cache key for a fetch request.
     *
     * The returned key must be deterministic and uniquely identify the logical
     * query represented by the request. Typically derived from
     * FetchRequest::canonicalDescription.
     */
    public function queryKeyForRequest(FetchRequest $request): string;
}
