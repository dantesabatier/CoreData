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
    private WeakMap $serializationShapes {
        get => $this->serializationShapes ??= new WeakMap();
    }
    /** @var WeakMap<ManagedObject, Dictionary<mixed>> */
    private WeakMap $preparedShapes {
        get => $this->preparedShapes ??= new WeakMap();
    }

    public static function shared(): ManagedObjectSerializationPreparer
    {
        return self::$shared ??= new ManagedObjectSerializationPreparer();
    }

    public function serializationKeysForObject(ManagedObject $object): ?ArrayClass
    {
        return $this->serializationShapes[$object] ?? null;
    }

    private function applySerializationShape(ManagedObject $object, Dictionary $shape): void
    {
        if ($shape->isEmpty) {
            return;
        }
        $serializationKeys = $shape->keys->filter(fn(string $key): bool => isset($object->entity->propertiesByName[$key]));
        if ($serializationKeys->isEmpty) {
            return;
        }
        $serializationKeys->insertAt(ManagedObjectObjectIDKey, 0);
        $serializationKeys->insertAt(ManagedObjectEntityNameKey, 1);
        $this->serializationShapes[$object] = $serializationKeys;
        $object->faultingState = ManagedObjectFaultingStateStable;
    }

    private function mergeShape(Dictionary $current, Dictionary $incoming): Dictionary
    {
        $merged = clone $current;
        foreach ($incoming as $key => $value) {
            $currentValue = $merged[$key];
            if ($currentValue instanceof Dictionary && $value instanceof Dictionary) {
                $merged[$key] = $this->mergeShape($currentValue, $value);
                continue;
            }
            $merged[$key] = $value;
        }
        return $merged;
    }

    private function shapeChanged(Dictionary $current, Dictionary $incoming): bool
    {
        foreach ($incoming as $key => $value) {
            $currentValue = $current[$key];
            if ($currentValue instanceof Dictionary && $value instanceof Dictionary) {
                if ($this->shapeChanged($currentValue, $value)) {
                    return true;
                }
                continue;
            }
            if ($currentValue !== $value) {
                return true;
            }
        }
        return false;
    }

    private function prepareObjectGraph(ManagedObject $object, Dictionary $shape): void
    {
        $currentShape = $this->preparedShapes[$object] ?? new Dictionary();
        if (!$this->shapeChanged($currentShape, $shape)) {
            return;
        }
        $mergedShape = $this->mergeShape($currentShape, $shape);
        $this->preparedShapes[$object] = $mergedShape;
        $this->applySerializationShape($object, $mergedShape);
        $context = $object->managedObjectContext;
        foreach ($mergedShape as $key => $subshape) {
            if (!$subshape instanceof Dictionary) {
                continue;
            }
            $value = $object->valueForKey($key);
            if ($value instanceof ManagedObject) {
                $this->prepareObjectGraph($value, $subshape);
            } elseif ($value instanceof ManagedObjectID) {
                $this->prepareObjectGraph($context->object($value), $subshape);
            } elseif ($value instanceof Sequence) {
                $value->forEach(fn(ManagedObject $object) => $this->prepareObjectGraph($object, $subshape));
            }
        }
    }

    /** @noinspection PhpMixedReturnTypeCanBeReducedInspection */
    public function serialized(ManagedObject $object, ?Dictionary $shape): mixed
    {
        if ($shape === null || $shape->isEmpty) {
            return $object;
        }
        $this->prepareObjectGraph($object, $shape);
        return $object;
    }
}
