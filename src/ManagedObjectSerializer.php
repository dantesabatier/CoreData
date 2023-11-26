<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class ManagedObjectSerializer
{
    private static ?ManagedObjectSerializer $shared = null;
    
    public static function shared(): ManagedObjectSerializer
    {
        if (self::$shared === null) {
            self::$shared = new ManagedObjectSerializer();
        }
        return self::$shared;
    }
    
    private function update(ManagedObject $object, Dictionary $dictionary): void
    {
        if ($dictionary->isEmpty) {
            return;
        }
        $serializationKeys = $dictionary->keys->filter(fn(string $key): bool => isset($object->entity->propertiesByName[$key]));
        if ($serializationKeys->isEmpty) {
            return;
        }
        $serializationKeys->insertAt(SQLEntity::primaryKeyName, 0);
        $object->serializationRule = SerializationRule::custom;
        $object->serializationKeys = $serializationKeys;
    }

    private function serialization(string $propertyName, Dictionary $dictionary): ?Dictionary
    {
        if ($dictionary[$propertyName]) {
            return $dictionary[$propertyName];
        }
        foreach ($dictionary as $key => $value) {
            if ($key === $propertyName) {
                return $value;
            }
            if ($value instanceof Dictionary) {
                $serialization = $this->serialization($propertyName, $value);
                if (!$serialization?->isEmpty) {
                    return $serialization;
                }
            }
        }
        return null;
    }

    private function serialize(ManagedObject $object, Dictionary $dictionary): void
    {
        $this->update($object, $dictionary);
        foreach ($object->entity as $property) {
            if ($property instanceof AttributeDescription) {
                continue;
            }
            $key = $property->name;
            $value = $object->primitiveValueForKey($key);
            if ($value === null) {
                continue;
            }
            $serialization = $this->serialization($key, $dictionary);
            if (!$serialization instanceof Dictionary) {
                continue;
            }
            $objs = $property instanceof RelationshipDescription ? ($property->isToMany ? $value : [$value]) : $value;
            foreach ($objs as $obj) {
                if ($obj instanceof ManagedObjectID) {
                    $obj = $object->managedObjectContext->object($obj);
                }
                if ($obj instanceof ManagedObject) {
                    $this->serialize($obj, $serialization);
                }
            }
        }
    }

    public function serialized(ManagedObject $object, ?Dictionary $dictionary): ManagedObject
    {
        if (!$dictionary || $dictionary->isEmpty) {
            return $object;
        }
        $this->serialize($object, $dictionary);
        return $object;
    }
}
