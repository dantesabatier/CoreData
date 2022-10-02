<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 30/06/20
 * Time: 21:33
 */

namespace Sabatier\CoreData;

/**
 * Class ManagedObjectContextConcurrencyType
 * Constants to indicate the concurrency pattern with which a context will be used.
 * @package Sabatier\CoreData
 */
enum ManagedObjectContextConcurrencyType: int
{
    /** Specifies that the context will be associated with a private dispatch queue. */
    case privateQueueConcurrencyType = 1;
    /** Specifies that the context will be associated with the main queue. */
    case mainQueueConcurrencyType = 2;
}
