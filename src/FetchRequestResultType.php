<?php

/**
 * @author Dante Sabatier <dantesabatier@me.com>
 * @version 1.0
 * @package Sabatier\CoreData
 */

namespace Sabatier\CoreData;

/**
 * Class FetchRequestResultType
 * Constants that specify the possible result types a fetch request can return.
 * @package Sabatier\CoreData
 */
enum FetchRequestResultType: int
{
    /** The request returns managed objects. */
    case managedObjectResultType = 0;
    /** The request returns managed object IDs. */
    case managedObjectIDResultType = 1;
    /** The request returns dictionaries. */
    case dictionaryResultType = 2;
    /** The request returns the count of the objects that match the request. */
    case countResultType = 3;
}
