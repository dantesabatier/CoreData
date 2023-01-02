<?php

namespace Sabatier\CoreData;

/**
 * Constants that specify the possible types of changes that are reported.
 */
enum FetchedResultsChangeType: int
{
    /** Specifies that an object was inserted. */
    case insert = 1;
    /** Specifies that an object was deleted. */
    case delete = 2;
    /** Specifies that an object was moved. */
    case move = 3;
    /** Specifies that an object was changed. */
    case update = 4;
}
