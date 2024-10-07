<?php

namespace Sabatier\CoreData;

use Override;

/** @internal */
class MinValueValidator extends ValueValidator
{
    #[Override]
    public function validate(mixed $object): bool
    {
        return $this->coerce($object) >= $this->value;
    }
}
