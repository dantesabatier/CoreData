<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Sequence;

/** @internal */
final class ManagedObjectSerializationPreparer
{
    private static ?ManagedObjectSerializationPreparer $shared = null;

    public static function shared(): ManagedObjectSerializationPreparer
    {
        return self::$shared ??= new ManagedObjectSerializationPreparer();
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

    private function prepareObjectGraph(ManagedObject $object, Dictionary $dictionary): void
    {
        $serializationKey = md5($dictionary->description);
        if ($object->serializationKey === $serializationKey) {
            return;
        }
        $this->applySerializationShape($object, $dictionary);
        $object->serializationKey = $serializationKey;
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
        $serializationKey = $dictionary && !$dictionary->isEmpty ? md5($dictionary->description) : null;
        if ($object->serializationKey === $serializationKey) {
            return $object;
        }
        if ($dictionary === null) {
            return $object;
        }
        $this->prepareObjectGraph($object, $dictionary);
        $object->serializationKey = $serializationKey;
        return $object;
    }
}
