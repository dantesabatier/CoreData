<?php

namespace Sabatier\CoreData;

use BackedEnum;
use Sabatier\Foundation\Value;

/** @internal */
class ValueValidator extends Validator
{
    public function validate(mixed $object): bool
    {
        if ($object instanceof Value || $object instanceof BackedEnum) {
            $object = $object->value;
        } elseif ($object instanceof ManagedObjectID) {
            $object = $object->referenceObject;
        } elseif (is_string($object)) {
            $object = strlen($object);
        }
        return $object <= $this->value;
    }
}
