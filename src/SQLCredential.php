<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 07/08/20
 * Time: 11:23
 */

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\Immutable;

/** @internal */
#[Immutable]
class SQLCredential
{
    public function __construct(public readonly string $user, public readonly ?string $password = null)
    {
    }
}
