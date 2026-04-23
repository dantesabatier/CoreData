<?php

namespace Sabatier\CoreData;

use Memcached;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * Memcached-backed implementation of RowCache.
 *
 * Stores snapshots and query results in a Memcached server, enabling
 * shared caching across multiple application instances.
 *
 * Characteristics:
 * - Shared across multiple servers
 * - Very fast network-based access
 * - No persistence (data may be evicted at any time)
 *
 * Recommended for:
 * - Distributed systems
 * - Read-heavy workloads
 * - Scenarios where occasional cache loss is acceptable
 */
final class MemcachedRowCache extends RowCache
{
    private Memcached $memcached;

    public function __construct(string $host = "127.0.0.1", int $port = 11211)
    {
        $this->memcached = new Memcached("core_data_cache");
        if (!count($this->memcached->getServerList())) {
            $this->memcached->addServer($host, $port);
        }
    }

    #[Override]
    public function currentGenerationForStore(string $storeIdentifier): int
    {
        $generation = $this->memcached->get("generation:$storeIdentifier");
        return is_numeric($generation) ? (int)$generation : 1;
    }

    #[Override]
    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        $key = "generation:$storeIdentifier";
        $generation = $this->memcached->increment($key);
        if ($generation === false) {
            $this->memcached->add($key, 1);
            return 1;
        }
        return $generation;
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): bool
    {
        $this->memcached->get($this->cacheKey($objectID, $property));
        return $this->memcached->getResultCode() === Memcached::RES_SUCCESS;
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): ?Dictionary
    {
        $data = $this->memcached->get($this->cacheKey($objectID, $property));
        if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS || $data === false) {
            return null;
        }
        return new Dictionary($data);
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = SecondsPerHourTimeInterval, ?PropertyDescription $property = null): void
    {
        $this->memcached->set($this->cacheKey($objectID, $property), $snapshot->array, $ttl);
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): void
    {
        $this->memcached->delete($this->cacheKey($objectID, $property));
    }

    #[Override]
    public function snapshots(ArrayClass $objectIDs): Dictionary
    {
        if ($objectIDs->isEmpty) {
            return new Dictionary();
        }
        /** @var Dictionary<string> $map */
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
        $cache = $this->memcached->getMulti($map->keys->array);
        if (!$cache) {
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
        $payload = $snapshots->reduce([],
            /**
             * @param array<string, array<string, mixed>> $carry
             * @param-out array<string, array<string, mixed>> $carry
             * @param Dictionary $snapshot
             * @param string $key
             * @return array<string, array<string, mixed>>
             */
            function (array &$carry, Dictionary $snapshot, string $key): array {
                $carry[$key] = $snapshot->array;
                return $carry;
            });
        $this->memcached->setMulti($payload, $ttl);
    }

    #[Override]
    public function deleteSnapshots(ArrayClass $objectIDs): void
    {
        if ($objectIDs->isEmpty) {
            return;
        }
        $keys = $objectIDs->map(fn(ManagedObjectID $objectID): string => $this->cacheKey($objectID))->array;
        $this->memcached->deleteMulti($keys);
    }

    #[Override]
    public function deletePropertySnapshots(Dictionary $snapshots): void
    {
        if ($snapshots->isEmpty) {
            return;
        }
        $this->memcached->deleteMulti($snapshots->flatMap(fn(ArrayClass $properties, string $uri): ArrayClass => $properties->map(fn(PropertyDescription $property): string => "$uri/$property->name"))->array);
    }
}
