<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class ManagedObjectSerializationPreparer
{
    private static ?ManagedObjectSerializationPreparer $shared = null;

    public static function shared(): ManagedObjectSerializationPreparer
    {
        self::$shared ??= new ManagedObjectSerializationPreparer();
        return self::$shared;
    }

    private function applySerializationShape(ManagedObject $object, Dictionary $dictionary): void
    {
        if ($dictionary->isEmpty) {
            return;
        }
        $serializationKeys = $dictionary->keys->filter(fn(string $key): bool => isset($object->entity->propertiesByName[$key]));
        if ($serializationKeys->isEmpty) {
            return;
        }
        $serializationKeys->insertAt(ManagedObjectObjectIDKey, 0);
        $serializationKeys->insertAt(ManagedObjectEntityNameKey, 1);
        $object->serializationRule = SerializationRule::custom;
        $object->serializationKeys = $serializationKeys;
    }

    private function subShapeForProperty(string $propertyName, Dictionary $dictionary): ?Dictionary
    {
        if ($dictionary[$propertyName]) {
            return $dictionary[$propertyName];
        }
        foreach ($dictionary as $key => $value) {
            if ($key === $propertyName) {
                return $value;
            }
            if ($value instanceof Dictionary) {
                $serialization = $this->subShapeForProperty($propertyName, $value);
                if (!$serialization?->isEmpty) {
                    return $serialization;
                }
            }
        }
        return null;
    }

    private function prepareObjectGraph(ManagedObject $object, Dictionary $dictionary): void
    {
        $this->applySerializationShape($object, $dictionary);
        foreach ($object->entity as $property) {
            if ($property instanceof AttributeDescription) {
                continue;
            }
            $key = $property->name;
            $value = $object->primitiveValueForKey($key);
            if ($value === null) {
                continue;
            }
            $serialization = $this->subShapeForProperty($key, $dictionary);
            if (!$serialization instanceof Dictionary) {
                continue;
            }
            $objs = $property instanceof RelationshipDescription ? ($property->isToMany ? $value : [$value]) : $value;
            foreach ($objs as $obj) {
                if ($obj instanceof ManagedObjectID) {
                    $obj = $object->managedObjectContext->object($obj);
                }
                if ($obj instanceof ManagedObject) {
                    $this->prepareObjectGraph($obj, $serialization);
                }
            }
        }
    }

    public function serialized(ManagedObject $object, ?Dictionary $dictionary): mixed
    {
        if (!$dictionary || $dictionary->isEmpty) {
            return $object;
        }
        $this->prepareObjectGraph($object, $dictionary);
        return $object;
    }
}
