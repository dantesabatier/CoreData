<?php

namespace Sabatier\CoreData;

use Closure;
use Sabatier\Foundation\KeyValueChange;
use Sabatier\Foundation\Set;

/** @internal */
final readonly class FaultingSetMutationMethod
{
    public function __construct(public string $name, public Closure $closure)
    {
    }

    /**
     * @param ManagedObject $obj
     * @param string $key
     * @param ManagedObject|Set<ManagedObject> $target
     * @param ManagedObjectID|null $value
     */
    private static function handleInverseRelationshipUpdate(ManagedObject $obj, string $key, ManagedObject|Set $target, ?ManagedObjectID $value): void
    {
        if ($inverse = self::toOneInverseRelationshipForKey($obj, $key)) {
            self::updateTargetsInInverseRelationship($target, $value, $inverse);
        }
    }

    private static function toOneInverseRelationshipForKey(ManagedObject $obj, string $key): ?RelationshipDescription
    {
        /** @var RelationshipDescription $relationship */
        $relationship = $obj->modeledRelationships[$key];
        $inverseRelationship = $relationship->inverseRelationship;
        return $inverseRelationship->isToMany ? null : $inverseRelationship;
    }

    /**
     * @param ManagedObject|Set<ManagedObject> $target
     * @param ManagedObjectID|null $value
     * @param RelationshipDescription $inverseRelationship
     */
    private static function updateTargetsInInverseRelationship(ManagedObject|Set $target, ?ManagedObjectID $value, RelationshipDescription $inverseRelationship): void
    {
        if ($target instanceof ManagedObject) {
            $target->setPrimitiveValueForKey($value, $inverseRelationship->name);
        } else {
            $target->forEach(fn(ManagedObject $object) => $object->setPrimitiveValueForKey($value, $inverseRelationship->name));
        }
    }

    public static function addObjectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("add%sObject", ucfirst($key)), function (ManagedObject $newObject) use ($obj, $key): void {
            /** @var FaultingSet $faultingSet */
            $faultingSet = $obj->valueForKey($key);
            if ($faultingSet->containsElement($newObject)) {
                return;
            }
            $inserted = new Set([$newObject]);
            $obj->willChangeValueForKey($key, KeyValueChange::insertion, $inserted);
            $faultingSet->insert($newObject);
            $obj->updateDirtyState($faultingSet, $key);
            self::handleInverseRelationshipUpdate($obj, $key, $newObject, $obj->objectID);
            $obj->didChangeValueForKey($key, KeyValueChange::insertion, $inserted);
        });
    }

    public static function removeObjectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%sObject", ucfirst($key)), function (ManagedObject $removedObject) use ($obj, $key): void {
            /** @var FaultingSet $faultingSet */
            $faultingSet = $obj->valueForKey($key);
            if (!$faultingSet->containsElement($removedObject)) {
                return;
            }
            // A removal must be announced with the objects that left the relationship: the context
            // turns that payload into the correlation-table DELETEs. Passing the remaining members
            // makes the save keep every row.
            $removed = new Set([$removedObject]);
            $obj->willChangeValueForKey($key, KeyValueChange::removal, $removed);
            $faultingSet->remove($removedObject);
            $obj->updateDirtyState($faultingSet, $key);
            self::handleInverseRelationshipUpdate($obj, $key, $removedObject, null);
            $obj->didChangeValueForKey($key, KeyValueChange::removal, $removed);
        });
    }

    public static function addMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("add%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $newObjects
             */
            function (Set $newObjects) use ($obj, $key): void {
                /** @var FaultingSet $faultingSet */
                $faultingSet = $obj->valueForKey($key);
                /** @var Set<ManagedObject> $objectsToInsert */
                $objectsToInsert = $newObjects->subtracting($faultingSet);
                if ($objectsToInsert->isEmpty) {
                    return;
                }
                $obj->willChangeValueForKey($key, KeyValueChange::insertion, $objectsToInsert);
                $faultingSet->formUnion($objectsToInsert);
                $obj->updateDirtyState($faultingSet, $key);
                self::handleInverseRelationshipUpdate($obj, $key, $objectsToInsert, $obj->objectID);
                $obj->didChangeValueForKey($key, KeyValueChange::insertion, $objectsToInsert);
            });
    }

    public static function removeMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $objectsToRemove
             */
            function (Set $objectsToRemove) use ($obj, $key): void {
                /** @var FaultingSet $faultingSet */
                $faultingSet = $obj->valueForKey($key);
                /** @var Set<ManagedObject> $removedObjects */
                $removedObjects = $faultingSet->intersection($objectsToRemove);
                if ($removedObjects->isEmpty) {
                    return;
                }
                $obj->willChangeValueForKey($key, KeyValueChange::removal, $removedObjects);
                $faultingSet->subtract($removedObjects);
                $obj->updateDirtyState($faultingSet, $key);
                self::handleInverseRelationshipUpdate($obj, $key, $removedObjects, null);
                $obj->didChangeValueForKey($key, KeyValueChange::removal, $removedObjects);
            });
    }

    public static function intersectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("intersect%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $intersectionSet
             */
            function (Set $intersectionSet) use ($obj, $key): Set {
                /** @var FaultingSet $faultingSet */
                $faultingSet = $obj->valueForKey($key);
                /** @var Set<ManagedObject> $objectsToRemove */
                $objectsToRemove = $faultingSet->subtracting($intersectionSet);
                if ($objectsToRemove->isEmpty) {
                    return $faultingSet;
                }
                $obj->willChangeValueForKey($key, KeyValueChange::removal, $objectsToRemove);
                $faultingSet->formIntersection($intersectionSet);
                $obj->updateDirtyState($faultingSet, $key);
                self::handleInverseRelationshipUpdate($obj, $key, $objectsToRemove, null);
                $obj->didChangeValueForKey($key, KeyValueChange::removal, $objectsToRemove);
                return $faultingSet;
            });
    }

    public static function setMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod("set" . ucfirst($key),
            /**
             * @param Set<ManagedObject> $newSet
             */
            function (Set $newSet) use ($obj, $key): void {
                $obj->setValueForKey($newSet, $key);
            });
    }
}
