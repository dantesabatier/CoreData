<?php

namespace Sabatier\CoreData;

/** @internal */
class SQLCountRequestContext extends SQLFetchRequestContext
{
    public function executeRequestCore(): bool
    {
        $this->request->resultType = FetchRequestResultType::countResultType;
        parent::executeRequestCore();
        return true;
    }
}
