<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

/** @internal */
class SQLObjectIDSetFetchRequestContext extends SQLFetchRequestContext
{
    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore, public readonly ArrayClass $idSets, public readonly string $columnName)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    public function executeRequestCore(): bool
    {
        return true;
    }
}
