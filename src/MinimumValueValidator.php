<?php

namespace Sabatier\CoreData;

use Override;

/** @internal */
class MinimumValueValidator extends ValueValidator
{
    #[Override]
    public function validate(mixed $object): bool
    {
        return $this->coerce($object) >= $this->value;
    }
}
