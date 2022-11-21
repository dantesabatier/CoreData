<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 20/06/20
 * Time: 21:57
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/**
 * The result that Core Data returns when executing a batch-insertion request.
 */
class BatchInsertResult extends PersistentStoreResult
{
    /** @var ArrayClass<ManagedObjectID>|Number The result of a batch-insertion request. */
    public readonly ArrayClass|Number $result;

    /**
     * BatchInsertResult constructor.
     * @param ArrayClass<ArrayClass<ManagedObjectID|Number>> $subresults
     * @param BatchInsertRequestResultType $resultType The type of result that Core Data returns from this request.
     */
    public function __construct(ArrayClass $subresults, public readonly BatchInsertRequestResultType $resultType = BatchInsertRequestResultType::statusOnly)
    {
        $sequence = $subresults->joined();
        $this->result = match ($this->resultType) {
            BatchInsertRequestResultType::statusOnly => new Number(!$sequence->containsElement(false)),
            BatchInsertRequestResultType::count => new Number($sequence->sum()),
            default => new ArrayClass($sequence)
        };
    }
}
