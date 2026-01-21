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
            $mutableSet = $obj->valueForKey($key);
            $mutableSet->insert($object);
            $obj->setValueForKey($mutableSet, $key);
        });
    }

    public static function removeObjectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%sObject", ucfirst($key)), function (ManagedObject $object) use ($obj, $key): void {
            $mutableSet = $obj->valueForKey($key);
            $mutableSet->remove($object);
            $obj->setValueForKey($mutableSet, $key);
        });
    }

    public static function addMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("add%s", ucfirst($key)),
            /**
             * @param Set<ManagedObject> $set
             */
            function (Set $set) use ($obj, $key): void {
                $mutableSet = $obj->valueForKey($key);
                $mutableSet->formUnion($set);
                $obj->setValueForKey($mutableSet, $key);
            });
    }

    public static function removeMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("remove%s", ucfirst($key)), function (Set $set) use ($obj, $key): void {
            $mutableSet = $obj->valueForKey($key);
            $mutableSet->removeAll($set->containsElement(...));
            $obj->setValueForKey($mutableSet, $key);
        });
    }

    public static function intersectMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod(sprintf("intersect%s", ucfirst($key)), function (Set $set) use ($obj, $key): Set {
            $mutableSet = $obj->valueForKey($key);
            $mutableSet->formIntersection($set);
            $obj->setValueForKey($mutableSet, $key);
            return $mutableSet;
        });
    }

    public static function setMethod(ManagedObject $obj, string $key): FaultingSetMutationMethod
    {
        return new FaultingSetMutationMethod("set" . ucfirst($key), function (Set $set) use ($obj, $key): void {
            $mutableSet = $obj->valueForKey($key);
            $mutableSet->setSet($set);
            $obj->setValueForKey($mutableSet, $key);
        });
    }
}
