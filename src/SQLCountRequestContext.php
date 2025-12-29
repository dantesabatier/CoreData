<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;

/** @internal */
final class SQLCountRequestContext extends SQLFetchRequestContext
{
    #[Override]
    protected function executeRequestCore(): bool
    {
        $this->request->resultType === FetchRequestResultType::countResultType ?: fatal_error(sprintf("CoreData: annotation: invalid result type: %s", human_readable_value($this->request->resultType)));
        $this->result = new ArrayClass([new Number((int)$this->queryStatement->fetchColumn())]);
        return true;
    }
}
