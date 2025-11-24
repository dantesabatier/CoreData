<?php

namespace Sabatier\CoreData;

use BackedEnum;
use Countable;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\typeof;

/** @internal */
abstract class ValueValidator extends Validator
{
    protected function coerce(mixed $object): mixed
    {
        if ($object instanceof Value || $object instanceof BackedEnum) {
            return $object->value;
        }
        if ($object instanceof Countable) {
            return $object->count();
        }
        if ($object instanceof ManagedObjectID) {
            return $object->referenceObject;
        }
        if (is_string($object)) {
            return strlen($object);
        }
        if (is_scalar($object)) {
            return $object;
        }
        fatal_error(sprintf("Invalid argument: (%s)%s", typeof($object), human_readable_value($object)));
    }
}
