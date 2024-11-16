<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:09
 */

namespace Sabatier\CoreData;

/**
 * A token that indicates which generation of the persistent store is being accessed.
 *
 * When a managed object context is pinned to a specific generation of the app data, a query generation token will be associated with that context.
 */
class QueryGenerationToken
{
    private static ?QueryGenerationToken $current = null;

    /**
     * A token that informs a context to use the current generation.
     * @return QueryGenerationToken
     */
    public static function current(): QueryGenerationToken
    {
        self::$current ??= new QueryGenerationToken();
        return self::$current;
    }
}
