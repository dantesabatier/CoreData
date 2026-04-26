<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 18:02
 */
namespace Sabatier\CoreData;

use Sabatier\Foundation\Set;

/** @internal */
final class RefreshRequest extends PersistentStoreRequest
{
    /** @var Set<ManagedObject> */
    private(set) Set $refreshObjects {
        get => $this->refreshObjects ??= new Set();
    }
    public RefreshRequestType $refreshType = RefreshRequestType::default;
}
