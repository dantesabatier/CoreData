<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class InMemoryRowCache extends RowCache
{
    /** @var Dictionary<array> */
    private Dictionary $storage;

    public function __construct()
    {
        $this->storage = new Dictionary();
    }

    public function currentGenerationForStore(string $storeIdentifier): int
    {
        return $this->storage["generation:$storeIdentifier"] ?? 1;
    }

    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        return $this->storage["generation:$storeIdentifier"] = $this->currentGenerationForStore($storeIdentifier) + 1;
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): bool
    {
        return $this->storage->offsetExists($this->cacheKey($objectID, $relationship));
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): ?Dictionary
    {
        $data = $this->storage->valueForKey($this->cacheKey($objectID, $relationship));
        return $data ? new Dictionary($data) : null;
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600, ?RelationshipDescription $relationship = null): void
    {
        $this->storage->setValueForKey($snapshot->array, $this->cacheKey($objectID, $relationship));
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void
    {
        $this->storage->removeValueForKey($this->cacheKey($objectID, $relationship));
    }
}
