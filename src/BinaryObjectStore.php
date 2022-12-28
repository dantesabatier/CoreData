<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/07/20
 * Time: 04:21
 */

namespace Sabatier\CoreData;

/** @internal */
class BinaryObjectStore extends MappedObjectStore
{
    public string $type = BinaryStoreType;
}
