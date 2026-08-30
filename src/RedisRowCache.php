<?php

namespace Sabatier\CoreData;

use Override;
use Redis;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use function Sabatier\Foundation\fatal_error;

/**
 * Redis-backed implementation of RowCache.
 *
 * Stores snapshots and query results in a Redis server, enabling distributed
 * caching across multiple application instances.
 *
 * Characteristics:
 * - Shared across multiple servers
 * - Network-based access (slower than in-memory, but scalable)
 * - Supports large datasets and persistence strategies
 *
 * Recommended for:
 * - Distributed systems
 * - Horizontal scaling environments
 * - High cache consistency requirements across nodes
 */
final class RedisRowCache extends RowCache
{
    private Redis $redis;

    public function __construct(string $host = "127.0.0.1", int $port = 6379)
    {
        $this->redis = new Redis();
        $this->redis->pconnect($host, $port);
    }

    #[Override]
    public function currentGenerationForStore(string $storeIdentifier): int
    {
        $generation = $this->redis->get("generation:$storeIdentifier");
        return is_numeric($generation) ? (int)$generation : 0;
    }

    #[Override]
    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        $generation = $this->redis->incr("generation:$storeIdentifier");
        return is_int($generation) ? $generation : 0;
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): bool
    {
        return (bool)$this->redis->exists($this->cacheKey($objectID, $property));
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): ?Dictionary
    {
        /** @var string|null $data */
        $data = $this->redis->get($this->cacheKey($objectID, $property));
        return $data ? new Dictionary(unserialize($data)) : null;
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = SecondsPerHourTimeInterval, ?PropertyDescription $property = null): void
    {
        $value = serialize($snapshot->array) ?: fatal_error("Unable to serialize snapshot for Redis row cache");
        $this->redis->setex($this->cacheKey($objectID, $property), $ttl, $value);
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?PropertyDescription $property = null): void
    {
        $this->redis->del($this->cacheKey($objectID, $property));
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
        $cache = $this->redis->mget($map->keys->array);
        if (!$cache) {
            return new Dictionary();
        }
        $values = $map->values;
        return new ArrayClass($cache)->reduce(new Dictionary(),
            /**
             * @param Dictionary<Dictionary<mixed>> $result
             * @param string|false|null $value Serialized snapshot from `mget`, or `false`/`null` when the key is absent.
             * @param int $index
             * @return Dictionary<Dictionary<mixed>>
             */
            function (Dictionary $result, string|false|null $value, int $index) use ($values): Dictionary {
                if ($value === false || $value === null) {
                    return $result;
                }
                $result[$values[$index]] = new Dictionary(unserialize($value));
                return $result;
            });
    }

    #[Override]
    public function setSnapshots(Dictionary $snapshots, int $ttl = SecondsPerHourTimeInterval): void
    {
        if ($snapshots->isEmpty) {
            return;
        }
        /** @var Redis $pipe */
        $pipe = $this->redis->multi(Redis::PIPELINE);
        $snapshots->forEach(fn(Dictionary $snapshot, string $key): bool|Redis => $pipe->setex($key, $ttl, serialize($snapshot->array) ?: fatal_error("Unable to serialize snapshot for Redis row cache")));
        $pipe->exec();
    }

    #[Override]
    public function deleteSnapshots(ArrayClass $objectIDs): void
    {
        if ($objectIDs->isEmpty) {
            return;
        }
        $this->redis->del($objectIDs->map(fn(ManagedObjectID $objectID): string => $this->cacheKey($objectID))->array);
    }

    #[Override]
    public function deletePropertySnapshots(Dictionary $snapshots): void
    {
        if ($snapshots->isEmpty) {
            return;
        }
        $this->redis->del($snapshots->flatMap(fn(ArrayClass $properties, string $uri): ArrayClass => $properties->map(fn(PropertyDescription $property) => "$uri/$property->name"))->array);
    }
}
