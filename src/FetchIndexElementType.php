<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:19
 */

namespace Sabatier\CoreData;

/**
 * Class FetchIndexElementType
 * Defines the possible types of index elements.
 * @package Sabatier\CoreData
 */
enum FetchIndexElementType: int
{
    /** A binary index type. */
    case binary = 0;
    /** An R-tree index type. */
    case rTree = 1;
    case bTree = 2;
}
