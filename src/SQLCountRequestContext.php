<?php

namespace Sabatier\CoreData;

use Override;

/** @internal */
class SQLCountRequestContext extends SQLFetchRequestContext
{
    #[Override]
    public function executeRequestCore(): bool
    {
        $this->request->resultType = FetchRequestResultType::countResultType;
        parent::executeRequestCore();
        return true;
    }
}
