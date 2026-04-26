<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

/**
 * Constants that specify the possible result types a fetch request can return.
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
