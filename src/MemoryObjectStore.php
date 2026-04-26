<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:08
 */
namespace Sabatier\CoreData;

use Override;

/** @internal */
final class MemoryObjectStore extends MappedObjectStore
{
    #[Override]
    public string $type {
        get => InMemoryStoreType;
    }
}
