<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 07/08/20
 * Time: 11:23
 */

namespace Sabatier\CoreData;

use SensitiveParameter;

/** @internal */
readonly class SQLCredential
{
    public function __construct(public string $user, #[SensitiveParameter] public ?string $password = null)
    {
    }
}
