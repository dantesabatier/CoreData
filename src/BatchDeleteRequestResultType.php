<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 16:12
 */
namespace Sabatier\CoreData;

/**
 * Result types for a batch-deletion request
 */
enum BatchDeleteRequestResultType: int
{
    /** A Boolean value that indicates whether the delete request succeeds. */
    case statusOnly = 0x0;
    /** A value that indicates the return type is an array of object identifiers that corresponds to the deleted rows. */
    case objectIDs = 0x1;
    /** A value that indicates the return type is the number of deleted rows. */
    case count = 0x2;
}
