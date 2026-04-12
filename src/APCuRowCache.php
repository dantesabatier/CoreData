<?php

namespace Sabatier\CoreData;

use Override;
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
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600, ?RelationshipDescription $relationship = null): void
    {
        apcu_store($this->cacheKey($objectID, $relationship), $snapshot->array, $ttl);
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void
    {
        apcu_delete($this->cacheKey($objectID, $relationship));
    }
}
