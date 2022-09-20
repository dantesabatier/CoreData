<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:08
 */

namespace Sabatier\CoreData;

/** @internal */
class MemoryObjectStore extends MappedObjectStore
{
    public function type(): string
    {
        return InMemoryStoreType;
    }
}
