<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;

/** @internal */
class SQLObjectIDSetFetchRequestContext extends SQLFetchRequestContext
{
    public readonly ArrayClass $idSets;
    public readonly string $columnName;

    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore, ArrayClass $idSets, string $columnName)
    {
        parent::__construct($request, $context, $sqlCore);
        $this->idSets = $idSets;
        $this->columnName = $columnName;
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        return true;
    }
}
