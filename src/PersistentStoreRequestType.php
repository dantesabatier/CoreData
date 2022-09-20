<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 07/05/20
 * Time: 11:08
 */

namespace Sabatier\CoreData;

/**
 * Class PersistentStoreRequestType
 * These constants specify the types of fetch request.
 * These constants are used by {@see PersistentStoreRequest::requestType}.
 * @package Sabatier\CoreData
 */
enum PersistentStoreRequestType: int
{
    /** Specifies that the request returns managed objects. */
    case fetchRequestType = 1;
    /** Specifies that the request saves managed objects. */
    case saveRequestType = 2;
    /** A request that inserts data into a persistent store using a batch of managed objects or dictionaries. */
    case batchInsertRequestType = 5;
    /** A request that updates data for multiple managed objects in a persistent store. */
    case batchUpdateRequestType = 6;
    /** A request that deletes data for multiple managed objects from a persistent store. */
    case batchDeleteRequestType = 7;
}
