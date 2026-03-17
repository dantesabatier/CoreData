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
            /** @var RelationshipDescription $relationship */
            $relationship = $obj->modeledRelationships[$key];
            $inverseRelationship = $relationship->inverseRelationship;
            if (!$inverseRelationship->isToMany) {
                $newObject->setPrimitiveValueForKey($obj->objectID, $inverseRelationship->name);
            }
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
            /** @var RelationshipDescription $relationship */
            $relationship = $obj->modeledRelationships[$key];
            $inverseRelationship = $relationship->inverseRelationship;
            if (!$inverseRelationship->isToMany) {
                $removedObject->setPrimitiveValueForKey(null, $inverseRelationship->name);
            }
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
                /** @var RelationshipDescription $relationship */
                $relationship = $obj->modeledRelationships[$key];
                $inverseRelationship = $relationship->inverseRelationship;
                if (!$inverseRelationship->isToMany) {
                    $objectsToInsert->forEach(fn(ManagedObject $object) => $object->setPrimitiveValueForKey($obj->objectID, $inverseRelationship->name));
                }
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
                /** @var RelationshipDescription $relationship */
                $relationship = $obj->modeledRelationships[$key];
                $inverseRelationship = $relationship->inverseRelationship;
                if (!$inverseRelationship->isToMany) {
                    $removedObjects->forEach(fn(ManagedObject $object) => $object->setPrimitiveValueForKey(null, $inverseRelationship->name));
                }
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
                if ($set->isSubset($intersectionSet)) {
                    return $set;
                }
                $obj->willChangeValueForKey($key, KeyValueChange::replacement, $set);
                $set->formIntersection($intersectionSet);
                $obj->didChangeValueForKey($key, KeyValueChange::replacement, $set);
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
