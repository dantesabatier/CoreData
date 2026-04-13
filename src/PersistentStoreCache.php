<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * A set of methods that a persistent store cache must implement to provide L2 row caching.
 * The cache stores raw snapshots of managed objects and query results to improve performance
 * by reducing database hits.
 */
interface PersistentStoreCache
{
    /**
     * Returns the current global generation integer for the specified store.
     * If no generation has been tracked yet, it should return a default value (e.g., 1).
     *
     * @param string $storeIdentifier The unique identifier of the persistent store.
     * @return int The current generation number.
     */
    public function currentGenerationForStore(string $storeIdentifier): int;

    /**
     * Atomically increments the global generation integer for the specified store
     * and returns the new generation number.
     *
     * This effectively advances the store's timeline and causes previously cached
     * query results tied to older generations to become stale for unpinned contexts.
     *
     * @param string $storeIdentifier The unique identifier of the persistent store.
     * @return int The newly incremented generation number.
     */
    public function advanceGenerationForStore(string $storeIdentifier): int;

    /**
     * Determines whether a snapshot exists in the cache for the given object ID
     * and optional relationship.
     *
     * This method must be a fast existence check and should not trigger any data
     * deserialization or I/O beyond what is necessary to determine presence.
     *
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param RelationshipDescription|null $relationship Optional relationship scope.
     * @return bool True if a snapshot exists in the cache, false otherwise.
     */
    public function hasSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): bool;

    /**
     * Returns the raw snapshot for the specified managed object ID.
     *
     * Snapshots represent attribute values only and do not include to-many relationships.
     *
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param RelationshipDescription|null $relationship Optional relationship scope.
     * @return Dictionary<mixed>|null The raw snapshot of the managed object, or null if it is not in the cache.
     */
    public function snapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): ?Dictionary;

    /**
     * Saves a raw snapshot for the specified managed object ID in the cache.
     *
     * Implementations should associate the snapshot with the current store generation.
     *
     * @param Dictionary<mixed> $snapshot The raw snapshot of the managed object to cache.
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param int $ttl The time-to-live for the cached snapshot, in seconds. Default is {@see SecondsPerHourTimeInterval}.
     * @param RelationshipDescription|null $relationship Optional relationship scope.
     */
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = SecondsPerHourTimeInterval, ?RelationshipDescription $relationship = null): void;

    /**
     * Removes the snapshot for the specified managed object ID from the cache.
     *
     * @param ManagedObjectID $objectID The object ID of the managed object.
     * @param RelationshipDescription|null $relationship Optional relationship scope.
     */
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void;

    /**
     * Retrieves multiple snapshots from the cache in a single operation.
     *
     * @param ArrayClass<ManagedObjectID> $objectIDs The object IDs to fetch.
     * @return Dictionary<Dictionary<mixed>> A dictionary keyed by the URI string of each ObjectID, with the corresponding raw snapshot as value.
     */
    public function snapshots(ArrayClass $objectIDs): Dictionary;

    /**
     * Saves multiple snapshots to the cache in a single operation.
     *
     * @param Dictionary<Dictionary> $snapshots A collection of snapshots
     * @param int $ttl The time-to-live for the cached snapshots, in seconds.
     */
    public function setSnapshots(Dictionary $snapshots, int $ttl = SecondsPerHourTimeInterval): void;

    /**
     * Removes the snapshots for the specified managed object IDs from the cache in a single operation.
     *
     * @param ArrayClass<ManagedObjectID> $objectIDs The object IDs whose snapshots should be removed.
     */
    public function deleteSnapshots(ArrayClass $objectIDs): void;

    /**
     * Returns the canonical cache key for a fetch request.
     *
     * The returned key must be deterministic and uniquely identify the logical
     * query represented by the request. Typically derived from
     * FetchRequest::canonicalDescription.
     *
     * Implementations must ensure that equivalent requests produce identical keys.
     */
    public function queryKeyForRequest(FetchRequest $request): string;
}
