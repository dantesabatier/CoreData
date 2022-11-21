<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:19
 */

namespace Sabatier\CoreData;

/**
 * Defines the possible types of index elements.
 */
enum FetchIndexElementType: int
{
    /** A binary index type. */
    case binary = 0;
    /** An R-tree index type. */
    case rTree = 1;
    case bTree = 2;
}
