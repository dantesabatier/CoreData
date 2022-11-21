<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:47
 */

namespace Sabatier\CoreData;

/**
 * The types of changes to managed objects reflected in persistent history.
 */
enum PersistentHistoryChangeType: int
{
    /** The insertion of a managed object into the persistent store. */
    case insert = 0;
    /** An update to a managed object's properties in the persistent store. */
    case update = 1;
    /** The deletion of a managed object from the persistent store. */
    case delete = 2;
}
