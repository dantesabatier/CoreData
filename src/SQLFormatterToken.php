<?php

namespace Sabatier\CoreData;

use const Sabatier\Foundation\NotFound;

/** @internal */
class SQLFormatterToken
{
    public int $index = NotFound;

    public function __construct(public ?string $value, public SQLFormatterTokenType $type)
    {
    }
}