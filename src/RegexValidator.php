<?php

namespace Sabatier\CoreData;

/** @internal */
class RegexValidator extends Validator
{
    public function validate(mixed $object): bool
    {
        return preg_match($this->value, (string)$object) === 1;
    }
}
