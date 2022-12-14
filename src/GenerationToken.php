<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 12:59
 */

namespace Sabatier\CoreData;

/** @internal */
readonly class GenerationToken
{
    public function __construct(public PersistentStore $store, public int $origin, public int $generation)
    {
    }
}
