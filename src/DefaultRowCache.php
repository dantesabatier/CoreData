<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class DefaultRowCache extends RowCache
{
    #[Override]
    public function currentGenerationForStore(string $storeIdentifier): int
    {
        return 1;
    }

    #[Override]
    public function advanceGenerationForStore(string $storeIdentifier): int
    {
        return $this->currentGenerationForStore($storeIdentifier);
    }

    #[Override]
    public function hasSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): bool
    {
        return false;
    }

    #[Override]
    public function snapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): ?Dictionary
    {
        return null;
    }

    #[Override]
    public function setSnapshot(Dictionary $snapshot, ManagedObjectID $objectID, int $ttl = 3600, ?RelationshipDescription $relationship = null): void
    {
    }

    #[Override]
    public function deleteSnapshot(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): void
    {
    }
}
