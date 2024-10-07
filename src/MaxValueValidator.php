<?php

namespace Sabatier\CoreData;

use Override;

/** @internal */
class MaxValueValidator extends ValueValidator
{
    #[Override]
    public function validate(mixed $object): bool
    {
        return $this->coerce($object) <= $this->value;
    }
}
