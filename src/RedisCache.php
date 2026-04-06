<?php

namespace Sabatier\CoreData;

use Redis;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class RedisCache implements PersistentStoreCache
{
    private Redis $redis;

    public function __construct(string $host = "127.0.0.1", int $port = 6379)
    {
        $this->redis = new Redis();
        $this->redis->connect($host, $port);
    }

    public function snapshotForKey(ManagedObjectID $objectID): ?Dictionary
    {
        $data = $this->redis->get((string)$objectID);
        return $data ? new Dictionary(unserialize($data)) : null;
    }

    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600): void
    {
        $this->redis->setex((string)$objectID, $ttl, serialize($snapshot->array));
    }

    public function deleteSnapshot(ManagedObjectID $objectID): void
    {
        $this->redis->del((string)$objectID);
    }
}
