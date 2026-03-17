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
        return !$inverseRelationship->isToMany ? $inverseRelationship : null;
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
            /** @var Set<ManagedObject> $set */
            $set = $obj->valueForKey($key);
            if ($set->containsElement($newObject)) {
                return;
            }
            $obj->willChangeValueForKey($key, KeyValueChange::insertion, $set);
            $set->insert($newObject);
            self::handleInverseRelationshipUpdate($obj, $key, $newObject, $obj->objectID);
            $obj->didChangeValueForKey($key, KeyValueChange::insertion, $set);
        });
    }

    public static function removeObjectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%sObject", ucfirst($key)), function (ManagedObject $removedObject) use ($obj, $key): void {
            /** @var Set<ManagedObject> $set */
            $set = $obj->valueForKey($key);
            if (!$set->containsElement($removedObject)) {
                return;
            }
            $obj->willChangeValueForKey($key, KeyValueChange::removal, $set);
            $set->remove($removedObject);
            self::handleInverseRelationshipUpdate($obj, $key, $removedObject, null);
            $obj->didChangeValueForKey($key, KeyValueChange::removal, $set);
        });
    }

    public static function addMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("add%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $newObjects
             */
            function (Set $newObjects) use ($obj, $key): void {
                /** @var Set<ManagedObject> $set */
                $set = $obj->valueForKey($key);
                /** @var Set<ManagedObject> $objectsToInsert */
                $objectsToInsert = $newObjects->subtracting($set);
                if ($objectsToInsert->isEmpty) {
                    return;
                }
                $obj->willChangeValueForKey($key, KeyValueChange::insertion, $set);
                $set->formUnion($objectsToInsert);
                self::handleInverseRelationshipUpdate($obj, $key, $objectsToInsert, $obj->objectID);
                $obj->didChangeValueForKey($key, KeyValueChange::insertion, $set);
            });
    }

    public static function removeMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $objectsToRemove
             */
            function (Set $objectsToRemove) use ($obj, $key): void {
                /** @var Set<ManagedObject> $set */
                $set = $obj->valueForKey($key);
                /** @var Set<ManagedObject> $removedObjects */
                $removedObjects = $set->intersection($objectsToRemove);
                if ($removedObjects->isEmpty) {
                    return;
                }
                $obj->willChangeValueForKey($key, KeyValueChange::removal, $set);
                $set->subtract($removedObjects);
                self::handleInverseRelationshipUpdate($obj, $key, $removedObjects, null);
                $obj->didChangeValueForKey($key, KeyValueChange::removal, $set);
            });
    }

    public static function intersectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("intersect%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $intersectionSet
             */
            function (Set $intersectionSet) use ($obj, $key): Set {
                /** @var Set<ManagedObject> $set */
                $set = $obj->valueForKey($key);
                /** @var Set<ManagedObject> $objectsToRemove */
                $objectsToRemove = $set->subtracting($intersectionSet);
                if ($objectsToRemove->isEmpty) {
                    return $set;
                }
                $obj->willChangeValueForKey($key, KeyValueChange::removal, $set);
                $set->formIntersection($intersectionSet);
                self::handleInverseRelationshipUpdate($obj, $key, $objectsToRemove, null);
                $obj->didChangeValueForKey($key, KeyValueChange::removal, $set);
                return $set;
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
