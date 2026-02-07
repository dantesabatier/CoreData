<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class MergeExecutor
{
    public function execute(MergeStrategy $strategy, MergeConflict|ConstraintConflict $conflict): void
    {
        [$object, $cachedSnapshot, $persistedSnapshot] = $this->snapshotsFor($conflict);
        $snapshot = $strategy->merge($cachedSnapshot, $persistedSnapshot);
        $object->updateFromSnapshot($snapshot);
        $object->awakeFromSnapshotEvents(SnapshotEventType::mergePolicy);
    }

    private function snapshotsFor(MergeConflict|ConstraintConflict $conflict): array
    {
        if ($conflict instanceof MergeConflict) {
            return [$conflict->sourceObject, $conflict->cachedSnapshot ?? $conflict->objectSnapshot, $conflict->persistedSnapshot ?? new Dictionary()];
        }
        return [$conflict->conflictingObjects[0], $conflict->conflictingSnapshots[0], $conflict->databaseSnapshot ?? $conflict->conflictingSnapshots[1]];
    }
}
