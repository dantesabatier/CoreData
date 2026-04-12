<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ObjectClass;

/**
 * A token that indicates which generation of the persistent store is being accessed.
 *
 * When a managed object context is pinned to a specific generation of the app data, a query generation token will be associated with that context.
 */
final class QueryGenerationToken extends ObjectClass
{
    public function __construct(public readonly GenerationToken $value)
    {
    }
}
