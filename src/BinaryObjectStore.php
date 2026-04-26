<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/07/20
 * Time: 04:21
 */
namespace Sabatier\CoreData;

use Override;

/** @internal */
final class BinaryObjectStore extends MappedObjectStore
{
    #[Override]
    public string $type {
        get => BinaryStoreType;
    }
}
