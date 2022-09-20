<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/07/20
 * Time: 09:00
 */

namespace Sabatier\CoreData;

use Exception;
use JetBrains\PhpStorm\Pure;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\ObjectClass;

/**
 * Class MergePolicy
 * A policy object that you use to resolve conflicts between the persistent store and in-memory versions of managed objects.
 * A conflict is a mismatch between state held at two different layers in the Core Data stack. A conflict can arise when you save a managed object context and you have stale data at another layer.
 * There are two places in which a conflict may occur:
 * Between the managed object context layer and its in-memory cached state at the persistent store coordinator layer.
 * Between the cached state at the persistent store coordinator and the external store (file, database, and so forth).
 * Conflicts are represented by instances of {@see MergeConflict}.
 * @package Sabatier\CoreData
 */
class MergePolicy extends ObjectClass
{
    /**
     * Returns a merge policy initialized with a given policy type.
     * If you override this method in a subclass, you should invoke the superclass's implementation with the merge policy that is closest to the behavior you want.
     * This will make it easier to use the superclass's implementation of {@see resolveConflicts()} and then customize the results.
     * Due to the complexity of merging to-many relationships, this class is designed with the expectation that you call super as the base implementation.
     * @param MergePolicyType $mergeType A merge policy type.
     */
    public function __construct(public readonly MergePolicyType $mergeType)
    {
    }

    /**
     * Resolves the conflicts in a given list.
     * If you override this method in a subclass, you should typically invoke the superclass's implementation in
     * addition to performing your own operations.
     * @param ArrayClass<MergeConflict> $list An array of merge conflicts (instances of {@see MergeConflict}).
     * @throws Exception
     */
    public function resolveConflicts(ArrayClass $list): void
    {
        /** @var ArrayClass<MergeConflict> $conflictList */
        $conflictList = new ArrayClass();
        foreach ($list as $mergeConflict) {
            $sourceObject = $mergeConflict->sourceObject;
            $cachedSnapshot = $mergeConflict->cachedSnapshot ?? $mergeConflict->objectSnapshot;
            $persistedSnapshot = $mergeConflict->persistedSnapshot ?? new Dictionary();
            if ($this->mergeType == MergePolicyType::errorMergePolicyType) {
                $conflictList->append($mergeConflict);
            } elseif ($this->mergeType == MergePolicyType::mergeByPropertyStoreTrumpMergePolicyType) {
                $sourceObject->setValuesForKeys($cachedSnapshot->merging($persistedSnapshot));
            } elseif ($this->mergeType == MergePolicyType::mergeByPropertyObjectTrumpMergePolicyType) {
                $sourceObject->setValuesForKeys($persistedSnapshot->merging($cachedSnapshot));
            } elseif ($this->mergeType == MergePolicyType::overwriteMergePolicyType) {
                $sourceObject->setValuesForKeys($cachedSnapshot);
            } elseif ($this->mergeType == MergePolicyType::rollbackMergePolicyType) {
                $sourceObject->setValuesForKeys($persistedSnapshot);
            }
        }
        if (!$conflictList->isEmpty()) {
            throw new Exception((new Error(CocoaErrorDomain, 133021, new Dictionary(['conflictList' => $conflictList->join(', ')])))->description());
        }
    }

    /**
     * Resolves the conflicts in a given list.
     * @param ArrayClass<ConstraintConflict> $list An array of merge conflicts (instances of {@see ConstraintConflict}).
     * @throws Exception
     */
    public function resolveConstraintConflicts(ArrayClass $list): void
    {
        /** @var ArrayClass<ConstraintConflict> $conflictList */
        $conflictList = new ArrayClass();
        foreach ($list as $constraintConflict) {
            $object = $constraintConflict->conflictingObjects[0];
            $objectSnapshot = $constraintConflict->conflictingSnapshots[0];
            $databaseSnapshot = $constraintConflict->databaseSnapshot ?? $constraintConflict->conflictingSnapshots[1];
            if ($this->mergeType == MergePolicyType::errorMergePolicyType) {
                $conflictList->append($constraintConflict);
            } elseif ($this->mergeType == MergePolicyType::mergeByPropertyStoreTrumpMergePolicyType) {
                $object->setValuesForKeys($objectSnapshot->merging($databaseSnapshot));
            } elseif ($this->mergeType == MergePolicyType::mergeByPropertyObjectTrumpMergePolicyType) {
                $object->setValuesForKeys($databaseSnapshot->merging($objectSnapshot));
            } elseif ($this->mergeType == MergePolicyType::overwriteMergePolicyType) {
                $object->setValuesForKeys($objectSnapshot);
            } elseif ($this->mergeType == MergePolicyType::rollbackMergePolicyType) {
                $object->setValuesForKeys($databaseSnapshot);
            }
            if ($databaseObject = $constraintConflict->databaseObject) {
                $object->objectID->referenceObject = $databaseObject->objectID->referenceObject;
                $object->objectID->persistentStore = $databaseObject->objectID->persistentStore;
            }
        }
        if (!$conflictList->isEmpty()) {
            throw new Exception((new Error(CocoaErrorDomain, 133021, new Dictionary(['conflictList' => $conflictList->join(', ')])))->description());
        }
    }

    /**
     * Resolves the conflicts in a given list.
     * @param ArrayClass<MergeConflict> $list An array of merge conflicts (instances of {@see MergeConflict}).
     * @throws Exception
     */
    public function resolveOptimisticLockingVersionConflicts(ArrayClass $list): void
    {
    }

    /**
     * Default policy for all managed object contexts.
     * @return MergePolicy
     */
    #[Pure]
    public static function error(): MergePolicy
    {
        return new MergePolicy(MergePolicyType::errorMergePolicyType);
    }

    /**
     * A policy that merges conflicts between the persistent store's version of the object and the current in-memory version by individual property, with the external changes trumping in-memory changes.
     * @return MergePolicy
     */
    #[Pure]
    public static function mergeByPropertyObjectTrump(): MergePolicy
    {
        return new MergePolicy(MergePolicyType::mergeByPropertyObjectTrumpMergePolicyType);
    }

    /**
     * A policy that merges conflicts between the persistent store's version of the object and the current in-memory version by individual property, with the in-memory changes trumping external changes.
     * @return MergePolicy
     */
    #[Pure]
    public static function mergeByPropertyStoreTrump(): MergePolicy
    {
        return new MergePolicy(MergePolicyType::mergeByPropertyStoreTrumpMergePolicyType);
    }

    /**
     * A policy that merges conflicts between the persistent store's version of the object and the current in-memory version by pushing the entire in-memory object to the persistent store.
     * @return MergePolicy
     */
    #[Pure]
    public static function overwrite(): MergePolicy
    {
        return new MergePolicy(MergePolicyType::overwriteMergePolicyType);
    }

    /**
     * A policy that merges conflicts between the persistent store's version of the object and the current in-memory version by discarding all state for the changed objects in conflict.
     * @return MergePolicy
     */
    #[Pure]
    public static function rollback(): MergePolicy
    {
        return new MergePolicy(MergePolicyType::rollbackMergePolicyType);
    }
}
