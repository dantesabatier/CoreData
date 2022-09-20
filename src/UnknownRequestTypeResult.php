<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 20/07/20
 * Time: 02:36
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;

/** @internal */
class UnknownRequestTypeResult extends PersistentStoreResult
{
    /**
     * @param ArrayClass<ArrayClass<ManagedObject|ManagedObjectID|Dictionary<mixed>|Number>>|BatchFaultingArray $subresults
     */
    public function __construct(public readonly ArrayClass|BatchFaultingArray $subresults)
    {
    }
}
