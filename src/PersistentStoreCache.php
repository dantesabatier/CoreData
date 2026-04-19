<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * Defines the contract for a persistent store L2 cache.
 *
 * Stores raw object snapshots and query results to reduce database access.
 * Implementations are expected to be thread-safe.
 */
interface PersistentStoreCache
{
    /**
     * Returns the current generation number for a store.
     *
     * @param string $storeIdentifier Unique store identifier.
     * @return int Current generation (default should be 1 if uninitialized).
     */
    public function currentGenerationForStore(string $storeIdentifier): int;

    /**
     * Increments and returns the store generation.
     *
     * @param string $storeIdentifier Unique store identifier.
     * @return int New generation value.
     */
    public function advanceGenerationForStore(string $storeIdentifier): int;

    /**
     * Checks if a snapshot exists for an object.
     *
     * Must be a fast existence check.
     *
     * @param ManagedObjectID $objectID Object identifier.
     * @param PropertyDescription|null $property Optional relationship scope.
     * @return bool
     */
    public function hasSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): bool;

    /**
     * Returns the snapshot for an object.
     *
     * @param ManagedObjectID $objectID Object identifier.
     * @param PropertyDescription|null $property Optional relationship scope.
     * @return Dictionary<mixed>|null
     */
    public function snapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): ?Dictionary;

    /**
     * Stores a snapshot.
     *
     * @param Dictionary<mixed> $snapshot Snapshot data.
     * @param ManagedObjectID $objectID Object identifier.
     * @param int $ttl Time-to-live in seconds.
     * @param PropertyDescription|null $property Optional relationship scope.
     */
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = SecondsPerHourTimeInterval, ?PropertyDescription $property = null): void;

    /**
     * Deletes a snapshot.
     *
     * @param ManagedObjectID $objectID Object identifier.
     * @param PropertyDescription|null $property Optional relationship scope.
     */
    public function deleteSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): void;

    /**
     * Returns multiple snapshots.
     *
     * @param ArrayClass<ManagedObjectID> $objectIDs Object identifiers.
     * @return Dictionary<Dictionary<mixed>> Keyed by ObjectID URI.
     */
    public function snapshots(ArrayClass $objectIDs): Dictionary;

    /**
     * Stores multiple snapshots.
     *
     * @param Dictionary<Dictionary<mixed>> $snapshots Keyed by ObjectID URI.
     * @param int $ttl Time-to-live in seconds.
     */
    public function setSnapshots(Dictionary $snapshots, int $ttl = SecondsPerHourTimeInterval): void;

    /**
     * Deletes multiple snapshots.
     *
     * @param ArrayClass<ManagedObjectID> $objectIDs Object identifiers.
     */
    public function deleteSnapshots(ArrayClass $objectIDs): void;

    /**
     * Returns a deterministic cache key for a fetch request.
     *
     * @param FetchRequest $request Fetch request.
     * @param QueryGenerationToken $token Generation token.
     * @return string
     */
    public function queryKeyForRequest(FetchRequest $request, QueryGenerationToken $token): string;
}
