<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 05/05/20
 * Time: 12:26
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ObjectClass;

/**
 * Class PersistentStoreRequest
 * Criteria used to retrieve data from or save data to a persistent store.
 * @package Sabatier\CoreData
 */
class PersistentStoreRequest extends ObjectClass
{
    /** @var ArrayClass<PersistentStore>|null The stores the request should be sent to. */
    public ?ArrayClass $affectedStores = null;

    /**
     * @param PersistentStoreRequestType $requestType The type of the fetch request
     */
    public function __construct(public readonly PersistentStoreRequestType $requestType = PersistentStoreRequestType::fetchRequestType)
    {
    }
}
