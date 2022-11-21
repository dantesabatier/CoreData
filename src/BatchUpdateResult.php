<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 16:05
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/**
 * The result returned when executing a batch update request.
 */
class BatchUpdateResult extends PersistentStoreResult
{
    /** @var ArrayClass<ManagedObjectID>|Number The result of a batch-update request. */
    public readonly ArrayClass|Number $result;

    /**
     * BatchUpdateResult constructor.
     * @param ArrayClass<ArrayClass<ManagedObjectID|Number>> $subresults
     * @param BatchUpdateRequestResultType $resultType
     */
    public function __construct(ArrayClass $subresults, public readonly BatchUpdateRequestResultType $resultType = BatchUpdateRequestResultType::statusOnly)
    {
        $sequence = $subresults->joined();
        $this->result = match ($this->resultType) {
            BatchUpdateRequestResultType::statusOnly => new Number(!$sequence->containsElement(false)),
            BatchUpdateRequestResultType::count => new Number($sequence->sum()),
            default => new ArrayClass($sequence)
        };
    }
}
