<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Sequence;
use WeakMap;

/** @internal */
final class ManagedObjectSerializationPreparer
{
    private static ?ManagedObjectSerializationPreparer $shared = null;

    /** @var WeakMap<ManagedObject, ArrayClass<string>> */
    private WeakMap $serializationShapes;

    public function __construct()
    {
        $this->serializationShapes = new WeakMap();
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
            if (!$v instanceof Sequence) {
                continue;
            }
            $value = $object->valueForKey($k);
            if ($value instanceof ManagedObject) {
                $this->prepareObjectGraph($value, $v);
            } elseif ($value instanceof ManagedObjectID) {
                $this->prepareObjectGraph($context->object($value), $v);
            } elseif ($value instanceof Sequence) {
                $value->forEach(fn(ManagedObject $object) => $this->prepareObjectGraph($object, $v));
            }
        }
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
