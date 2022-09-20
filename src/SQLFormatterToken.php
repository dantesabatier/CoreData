<?php

namespace Sabatier\CoreData;

/** @internal */
class SQLFormatterToken
{
    public int $index = NotFound;

    public function __construct(public ?string $value, public SQLFormatterTokenType $type)
    {
    }
}