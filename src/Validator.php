<?php

namespace Sabatier\CoreData;

/** @internal */
abstract class Validator
{
    public function __construct(public readonly mixed $value)
    {
    }

    public abstract function validate(mixed $object): bool;
}
