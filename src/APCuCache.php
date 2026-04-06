<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class APCuCache implements PersistentStoreCache
{
    public function snapshotForKey(ManagedObjectID $objectID): ?Dictionary
    {
        $data = apcu_fetch((string)$objectID, $success);
        return $success ? new Dictionary($data) : null;
    }

    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600): void
    {
        apcu_store((string)$objectID, $snapshot->array, $ttl);
    }

    public function deleteSnapshot(ManagedObjectID $objectID): void
    {
        apcu_delete((string)$objectID);
    }
}
