<?php

namespace Sabatier\CoreData;

use Override;
use Redis;
use Sabatier\Foundation\Dictionary;
use function Sabatier\Foundation\fatal_error;

/** @internal */
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
        return is_numeric($generation) ? (int)$generation : 1;
    }

    #[Override]
    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        $generation = $this->redis->incr("generation:$storeIdentifier");
        return is_int($generation) ? $generation : 1;
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): bool
    {
        return (bool)$this->redis->exists($this->cacheKey($objectID, $relationship));
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): ?Dictionary
    {
        /** @var string|null $data */
        $data = $this->redis->get($this->cacheKey($objectID, $relationship));
        return $data ? new Dictionary(unserialize($data)) : null;
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600, ?RelationshipDescription $relationship = null): void
    {
        $value = serialize($snapshot->array) ?: fatal_error("Unable to serialize snapshot for Redis row cache");
        $this->redis->setex($this->cacheKey($objectID, $relationship), $ttl, $value);
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void
    {
        $this->redis->del($this->cacheKey($objectID, $relationship));
    }
}
