<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
final readonly class ConflictDetectionService
{
    public function __construct(private SnapshotProvider $snapshotProvider, private VersioningStrategy $versioningStrategy, private DeleteRuleConflictDetector $deleteRuleDetector, private MergePolicy $mergePolicy)
    {
    }

    /**
     * @param ManagedObject $object
     * @return Dictionary<mixed>
     */
    private function baselineSnapshotFor(ManagedObject $object): Dictionary
    {
        return $object->originalSnapshot?->filter(fn(mixed $value, string $key): bool => match ($key) {
            ManagedObjectObjectIDKey, ManagedObjectEntityNameKey, ManagedObjectVersionKey => true,
            default => $object->modeledAttributes->offsetExists($key) && !$object->transientProperties->offsetExists($key),
        }) ?? new Dictionary();
    }

    /**
     * @throws Exception
     */
    public function detectConflicts(ManagedObject $object): void
    {
        if ($object->objectID->isTemporaryID || !$object->originalSnapshot) {
            return;
        }
        $baselineSnapshot = $this->baselineSnapshotFor($object);
        $snapshotKeys = $baselineSnapshot->keys;
        if ($object->isDeleted) {
            $conflicts = $this->deleteRuleDetector->conflictsForDeletion($object, $baselineSnapshot);
            $this->mergePolicy->resolveConflicts($conflicts);
            return;
        }
        if ($object->isUpdated) {
            $storeSnapshot = $this->snapshotProvider->snapshot($object, $snapshotKeys);
            if ($storeSnapshot && $this->versioningStrategy->hasConflict($baselineSnapshot, $storeSnapshot)) {
                $this->mergePolicy->resolveOptimisticLockingVersionConflicts(new ArrayClass([new MergeConflict($object, $storeSnapshot[ManagedObjectVersionKey], $baselineSnapshot[ManagedObjectVersionKey], $baselineSnapshot, $storeSnapshot)]));
            }
        }
    }
}

