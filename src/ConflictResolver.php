<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class ConflictResolver
{
    public function resolve(MergeConflict|ConstraintConflict $conflict, MergeStrategy $strategy): void
    {
        [$object, $cachedSnapshot, $persistedSnapshot] = $this->snapshotsFor($conflict);
        $snapshot = $strategy->merge($cachedSnapshot, $persistedSnapshot);
        $object->isSuppressingChangeNotifications = true;
        $object->isSuppressingKVO = true;
        $object->updateFromSnapshot($snapshot);
        $object->isSuppressingKVO = false;
        $object->awakeFromSnapshotEvents(SnapshotEventType::mergePolicy);
        $object->isSuppressingChangeNotifications = false;
    }

    /**
     * @param MergeConflict|ConstraintConflict $conflict
     * @return array{ManagedObject, Dictionary<mixed>, Dictionary<mixed>}
     */
    private function snapshotsFor(MergeConflict|ConstraintConflict $conflict): array
    {
        if ($conflict instanceof MergeConflict) {
            return [$conflict->sourceObject, $conflict->cachedSnapshot ?? $conflict->objectSnapshot, $conflict->persistedSnapshot ?? new Dictionary()];
        }
        return [$conflict->conflictingObjects[0], $conflict->conflictingSnapshots[0], $conflict->databaseSnapshot ?? $conflict->conflictingSnapshots[1]];
    }
}
