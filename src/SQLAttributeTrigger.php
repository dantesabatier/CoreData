<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;

class SQLAttributeTrigger extends ObjectClass
{
    public function __construct(Dictionary $userInfo, string $attributeName, SQLEntity $entity)
    {
    }

    /**
     * @throws Exception
     */
    public function validate(): bool
    {
        return false;
    }
}
