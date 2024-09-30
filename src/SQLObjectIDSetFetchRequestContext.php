<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;

/** @internal */
class SQLObjectIDSetFetchRequestContext extends SQLFetchRequestContext
{
    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore, public readonly ArrayClass $idSets, public readonly string $columnName)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        return true;
    }
}
