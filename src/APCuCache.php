<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class APCuCache implements PersistentStoreCache
{
    #[Override]
    public function snapshotForKey(ManagedObjectID $objectID): ?Dictionary
    {
        $data = apcu_fetch((string)$objectID, $success);
        return $success ? new Dictionary($data) : null;
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600): void
    {
        apcu_store((string)$objectID, $snapshot->array, $ttl);
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID): void
    {
        apcu_delete((string)$objectID);
    }
}
