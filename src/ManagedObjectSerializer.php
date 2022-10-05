<?php

/**
 * @author Dante Sabatier <dantesabatier@me.com>
 * @version 1.0
 * @package Sabatier\CoreData
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class ManagedObjectSerializer
{
    private static function serializationKeys(ManagedObject $object, Dictionary $dictionary): ArrayClass
    {
        return $dictionary->filter(fn(mixed $value, string $key): bool => $object->entity->propertiesByName[$key] !== null)->keys;
    }

    private static function update(ManagedObject $object, Dictionary $dictionary): void
    {
        if ($dictionary->isEmpty()) {
            return;
        }
        $serializationKeys = self::serializationKeys($object, $dictionary);
        if ($serializationKeys->isEmpty()) {
            return;
        }
        $object->serializationRule = SerializationRule::custom;
        $object->serializationKeys = $serializationKeys;
    }

    private static function serialization(string $propertyName, Dictionary $dictionary): ?Dictionary
    {
        if ($dictionary[$propertyName]) {
            return $dictionary[$propertyName];
        }
        foreach ($dictionary as $key => $value) {
            if ($key === $propertyName) {
                return $value;
            }
            if ($value instanceof Dictionary) {
                $serialization = self::serialization($propertyName, $value);
                if (!$serialization?->isEmpty()) {
                    return $serialization;
                }
            }
        }
        return null;
    }

    private static function serialize(ManagedObject $object, Dictionary $dictionary): void
    {
        self::update($object, $dictionary);
        $entity = $object->entity;
        $context = $object->managedObjectContext;
        foreach ($entity as $property) {
            if ($property instanceof AttributeDescription) {
                continue;
            }
            $key = $property->name;
            $value = $object->primitiveValueForKey($key);
            if ($value === null) {
                continue;
            }
            $serialization = self::serialization($key, $dictionary);
            if (!$serialization instanceof Dictionary) {
                continue;
            }
            $objs = $value;
            if ($property instanceof RelationshipDescription) {
                $objs = $property->isToMany ? $value : [$value];
            }
            foreach ($objs as $obj) {
                if ($obj instanceof ManagedObjectID) {
                    $obj = $context->object($obj);
                }
                if ($obj instanceof ManagedObject) {
                    self::serialize($obj, $serialization);
                }
            }
        }
    }

    public static function serialized(ManagedObject $object, ?Dictionary $serialization): ManagedObject
    {
        if (!$serialization || $serialization->isEmpty()) {
            return $object;
        }
        self::serialize($object, $serialization);
        return $object;
    }
}
