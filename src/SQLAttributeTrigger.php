<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ObjectClass;

final class SQLAttributeTrigger extends ObjectClass
{
    public function validate(): bool
    {
        return false;
    }
}
