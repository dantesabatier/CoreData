<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

abstract class SQLBatchOperationRequestContext extends SQLStoreRequestContext
{
    /** @var ArrayClass<ManagedObjectID> */
    protected(set) ArrayClass $affectedObjectIDs {
        get => $this->affectedObjectIDs ??= new ArrayClass();
    }
}
