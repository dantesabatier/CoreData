<?php

namespace Sabatier\CoreData;

use Override;

/** @internal */
class SQLCountRequestContext extends SQLFetchRequestContext
{
    #[Override]
    protected function executeRequestCore(): bool
    {
        $this->request->resultType = FetchRequestResultType::countResultType;
        parent::executeRequestCore();
        return true;
    }
}
