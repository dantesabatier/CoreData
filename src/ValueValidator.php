<?php

namespace Sabatier\CoreData;

use BackedEnum;
use Sabatier\Foundation\Value;

/** @internal */
abstract class ValueValidator extends Validator
{
    public function coerce(mixed $object): mixed
    {
        if ($object instanceof Value || $object instanceof BackedEnum) {
            return $object->value;
        }
        if ($object instanceof ManagedObjectID) {
            return $object->referenceObject;
        }
        if (is_string($object)) {
            return strlen($object);
        }
        return $object;
    }
}
