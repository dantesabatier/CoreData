<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 20/06/20
 * Time: 21:51
 */

namespace Sabatier\CoreData;

/**
 * Class BatchInsertRequestResultType
 * Result types for a batch-insertion request.
 * @package Sabatier\CoreData
 */
enum BatchInsertRequestResultType: int
{
    /** A value that indicates that the return type is a Boolean value representing whether the batch-insertion request succeeded. */
    case statusOnly = 0x0;
    /** A value that indicates the return type is an array of object IDs that corresponds to the inserted rows. */
    case objectIDs = 0x1;
    /** A value that indicates that the return type is the number of inserted rows. */
    case count = 0x2;
}
