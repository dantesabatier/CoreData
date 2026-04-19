<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Sequence;
use WeakMap;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class ManagedObjectSerializationPreparer
{
    private static ?ManagedObjectSerializationPreparer $shared = null;

    /** @var WeakMap<ManagedObject, ArrayClass<string>> */
    private WeakMap $serializationShapes {
        get => $this->serializationShapes ??= new WeakMap();
    }

    public static function shared(): ManagedObjectSerializationPreparer
    {
        return self::$shared ??= new ManagedObjectSerializationPreparer();
    }

    public function serializationKeysForObject(ManagedObject $object): ?ArrayClass
    {
        return $this->serializationShapes[$object] ?? null;
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
        $this->serializationShapes[$object] = $serializationKeys;
    }

    private function prepareObjectGraph(ManagedObject $object, Dictionary $dictionary): void
    {
        if (isset($this->serializationShapes[$object])) {
            return;
        }
        $this->applySerializationShape($object, $dictionary);
        $context = $object->managedObjectContext;
        foreach ($dictionary as $k => $v) {
            $property = $object->allProperties[$k] ?? fatal_error();
            if (!$property instanceof RelationshipDescription) {
                continue;
            }
            $value = $object->valueForKey($k);
            if ($value instanceof ManagedObject) {
                $this->prepareObjectGraph($value, $v);
            } elseif ($value instanceof ManagedObjectID) {
                $this->prepareObjectGraph($context->object($value), $v);
            } elseif ($value instanceof Sequence && ($shape = $this->subShapeForProperty($k, $dictionary))) {
                $value->forEach(fn(ManagedObject $object) => $this->prepareObjectGraph($object, $shape));
            }
        }
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

    /** @noinspection PhpMixedReturnTypeCanBeReducedInspection */
    public function serialized(ManagedObject $object, ?Dictionary $dictionary): mixed
    {
        if ($dictionary === null || $dictionary->isEmpty) {
            return $object;
        }
        $this->prepareObjectGraph($object, $dictionary);
        return $object;
    }
}
