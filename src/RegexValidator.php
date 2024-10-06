<?php

namespace Sabatier\CoreData;

use Override;

/** @internal */
class RegexValidator extends Validator
{
    #[Override]
    public function validate(mixed $object): bool
    {
        return preg_match($this->value, (string)$object) === 1;
    }
}
