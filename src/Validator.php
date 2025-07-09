<?php

namespace Sabatier\CoreData;

/** @internal */
abstract class Validator
{
    public function __construct(public readonly mixed $value)
    {
    }

    abstract public function validate(mixed $object): bool;
}
