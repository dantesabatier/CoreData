<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 12:59
 */

namespace Sabatier\CoreData;

/** @internal */
class GenerationToken
{
    public function __construct(public readonly PersistentStore $store, public readonly int $origin, public readonly int $generation)
    {
    }
}
