<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 16:09
 */
namespace Sabatier\CoreData;

/**
 * Result types for a batch-update request.
 */
enum BatchUpdateRequestResultType: int
{
    /** A value that indicates the return type is a Boolean value representing whether the batch-update request succeeds. */
    case statusOnly = 0x0;
    /** A value that indicates the return type is an array of object IDs that corresponds to the updated rows. */
    case objectIDs = 0x1;
    /** A value that indicates the return type is the number of updated rows. */
    case count = 0x2;
}
