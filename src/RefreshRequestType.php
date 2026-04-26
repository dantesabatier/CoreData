<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/07/20
 * Time: 23:41
 */
namespace Sabatier\CoreData;

/** @internal */
enum RefreshRequestType: int
{
    case default = 0;
    case merge = 1;
}
