<?php

namespace Sabatier\CoreData;

use Closure;
use Sabatier\Foundation\Set;

/** @internal */
final readonly class FaultingSetMutationMethod
{
    public function __construct(public string $name, public Closure $closure)
    {
    }

    public static function addObjectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("add%sObject", ucfirst($key)), function (ManagedObject $object) use ($obj, $key): void {
            /** @var Set<ManagedObject> $set */
            $set = $obj->valueForKey($key);
            $set->insert($object);
            $obj->setValueForKey($set, $key);
        });
    }

    public static function removeObjectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%sObject", ucfirst($key)), function (ManagedObject $object) use ($obj, $key): void {
            /** @var Set<ManagedObject> $set */
            $set = $obj->valueForKey($key);
            $set->remove($object);
            $obj->setValueForKey($set, $key);
        });
    }

    public static function addMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("add%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $newSet
             */
            function (Set $newSet) use ($obj, $key): void {
                /** @var Set<ManagedObject> $set */
                $set = $obj->valueForKey($key);
                $set->formUnion($newSet);
                $obj->setValueForKey($set, $key);
            });
    }

    public static function removeMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $newSet
             */
            function (Set $newSet) use ($obj, $key): void {
                /** @var Set<ManagedObject> $set */
                $set = $obj->valueForKey($key);
                $set->removeAll($newSet->containsElement(...));
                $obj->setValueForKey($set, $key);
            });
    }

    public static function intersectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("intersect%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $newSet
             */
            function (Set $newSet) use ($obj, $key): Set {
                /** @var Set<ManagedObject> $set */
                $set = $obj->valueForKey($key);
                $set->formIntersection($newSet);
                $obj->setValueForKey($set, $key);
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
                /** @var Set<ManagedObject> $set */
                $set = $obj->valueForKey($key);
                $set->setSet($newSet);
                $obj->setValueForKey($set, $key);
            });
    }
}
