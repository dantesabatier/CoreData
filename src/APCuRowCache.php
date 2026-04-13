<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class APCuRowCache extends RowCache
{
    #[Override]
    public function currentGenerationForStore(string $storeIdentifier): int
    {
        $key = "generation:$storeIdentifier";
        $generation = apcu_fetch($key, $success);
        if ($success) {
            return (int)$generation;
        }
        apcu_add($key, 1);
        return 1;
    }

    #[Override]
    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        $key = "generation:$storeIdentifier";
        $newGeneration = apcu_inc($key, 1, $success);
        if ($success) {
            return $newGeneration;
        }
        apcu_add($key, 1);
        return 1;
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): bool
    {
        return apcu_exists($this->cacheKey($objectID, $relationship));
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): ?Dictionary
    {
        $data = apcu_fetch($this->cacheKey($objectID, $relationship), $success);
        return $success ? new Dictionary($data) : null;
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = SecondsPerHourTimeInterval, ?RelationshipDescription $relationship = null): void
    {
        apcu_store($this->cacheKey($objectID, $relationship), $snapshot->array, $ttl);
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void
    {
        apcu_delete($this->cacheKey($objectID, $relationship));
    }

    #[Override]
    public function snapshots(ArrayClass $objectIDs): Dictionary
    {
        if ($objectIDs->isEmpty) {
            return new Dictionary();
        }
        $map = $objectIDs->reduce(new Dictionary(),
            /**
             * @param Dictionary<string> $map
             * @param ManagedObjectID $objectID
             * @return Dictionary<string>
             */
            function (Dictionary $map, ManagedObjectID $objectID): Dictionary {
                $map[$this->cacheKey($objectID)] = $objectID->uriRepresentation()->absoluteString;
                return $map;
            });
        /** @var array<string, mixed> $cache */
        $cache = apcu_fetch($map->keys->array);
        if (empty($cache)) {
            return new Dictionary();
        }
        return new Dictionary($cache)->reduce(new Dictionary(),
            /**
             * @param Dictionary<Dictionary<mixed>> $result
             * @param array<string, mixed> $value
             * @param string $key
             * @return Dictionary<Dictionary<mixed>>
             */
            function (Dictionary $result, array $value, string $key) use ($map): Dictionary {
                $result[(string)$map[$key]] = new Dictionary($value);
                return $result;
            });
    }

    #[Override]
    public function setSnapshots(Dictionary $snapshots, int $ttl = SecondsPerHourTimeInterval): void
    {
        if ($snapshots->isEmpty) {
            return;
        }
        apcu_store($snapshots->reduce([],
            /**
             * @param array $result
             * @param Dictionary<mixed> $snapshot
             * @param string $key
             * @return array
             */
            function (array &$result, Dictionary $snapshot, string $key): array {
                $result[$key] = $snapshot->array;
                return $result;
            }), null, $ttl);
    }

    #[Override]
    public function deleteSnapshots(ArrayClass $objectIDs): void
    {
        if ($objectIDs->isEmpty) {
            return;
        }
        apcu_delete($objectIDs->map(fn(ManagedObjectID $objectID): string => $this->cacheKey($objectID))->array);
    }
}
