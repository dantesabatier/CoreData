<?php

namespace Sabatier\CoreData;

use Closure;
use Sabatier\Foundation\Set;

/** @internal */
readonly class FaultingMutableSetMutationMethod
{
    public function __construct(public string $name, public Closure $closure)
    {
    }

    public static function addObjectMethod(ManagedObject $obj, string $key): FaultingMutableSetMutationMethod
    {
        return new FaultingMutableSetMutationMethod(sprintf("add%sObject", ucfirst($key)), function (ManagedObject $object) use ($obj, $key): void {
            $mutableSet = clone $obj->mutableSetValueForKey($key);
            $mutableSet->append($object);
            $obj->setValueForKey($mutableSet, $key);
        });
    }

    public static function removeObjectMethod(ManagedObject $obj, string $key): FaultingMutableSetMutationMethod
    {
        return new FaultingMutableSetMutationMethod(sprintf("remove%sObject", ucfirst($key)), function (ManagedObject $object) use ($obj, $key): void {
            $mutableSet = clone $obj->mutableSetValueForKey($key);
            $mutableSet->remove($object);
            $obj->setValueForKey($mutableSet, $key);
        });
    }

    public static function addMethod(ManagedObject $obj, string $key): FaultingMutableSetMutationMethod
    {
        return new FaultingMutableSetMutationMethod(sprintf("add%s", ucfirst($key)), function (Set $set) use ($obj, $key): void {
            $mutableSet = clone $obj->mutableSetValueForKey($key);
            $mutableSet->formUnion($set);
            $obj->setValueForKey($mutableSet, $key);
        });
    }

    public static function removeMethod(ManagedObject $obj, string $key): FaultingMutableSetMutationMethod
    {
        return new FaultingMutableSetMutationMethod(sprintf("remove%s", ucfirst($key)), function (Set $set) use ($obj, $key): void {
            $mutableSet = clone $obj->mutableSetValueForKey($key);
            $mutableSet->removeAll(fn(ManagedObject $object): bool => $set->containsElement($object));
            $obj->setValueForKey($mutableSet, $key);
        });
    }

    public static function intersectMethod(ManagedObject $obj, string $key): FaultingMutableSetMutationMethod
    {
        return new FaultingMutableSetMutationMethod(sprintf("intersect%s", ucfirst($key)), function (Set $set) use ($obj, $key): Set {
            $mutableSet = clone $obj->mutableSetValueForKey($key);
            $mutableSet->formIntersection($set);
            $obj->setValueForKey($mutableSet, $key);
            return $mutableSet;
        });
    }

    public static function setMethod(ManagedObject $obj, string $key): FaultingMutableSetMutationMethod
    {
        return new FaultingMutableSetMutationMethod("set" . ucfirst($key), function (Set $set) use ($obj, $key): void {
            $mutableSet = clone $obj->mutableSetValueForKey($key);
            $mutableSet->setSet($set);
            $obj->setValueForKey($mutableSet, $key);
        });
    }
}
