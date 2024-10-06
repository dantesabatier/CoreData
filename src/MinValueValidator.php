<?php

namespace Sabatier\CoreData;

/** @internal */
class MinValueValidator extends ValueValidator
{
    public function validate(mixed $object): bool
    {
        return $this->coerce($object) >= $this->value;
    }
}
