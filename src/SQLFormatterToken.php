<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use const Sabatier\Foundation\NotFound;

/** @internal */
final class SQLFormatterToken
{
    public int $index = NotFound;

    public function __construct(public SQLFormatterTokenType $type, public ?string $value = null)
    {
    }
}
