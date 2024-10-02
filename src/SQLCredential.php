<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 07/08/20
 * Time: 11:23
 */

namespace Sabatier\CoreData;

/** @internal */
readonly class SQLCredential
{
    public function __construct(public string $user, public ?string $password = null)
    {
    }
}
