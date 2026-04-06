<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final readonly class InMemoryCache implements PersistentStoreCache
{
    /** @var Dictionary<array> */
    private Dictionary $storage;

    public function __construct()
    {
        $this->storage = new Dictionary();
    }

    public function snapshotForKey(ManagedObjectID $objectID): ?Dictionary
    {
        $data = $this->storage->valueForKey((string)$objectID);
        return $data ? new Dictionary($data) : null;
    }

    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600): void
    {
        $this->storage->setValueForKey($snapshot->array, (string)$objectID);
    }

    public function deleteSnapshot(ManagedObjectID $objectID): void
    {
        $this->storage->removeValueForKey((string)$objectID);
    }
}
