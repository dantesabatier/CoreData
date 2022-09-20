<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 12:07
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/**
 * Class PersistentHistoryResult
 * The result of a request to fetch persistent history.
 * @package Sabatier\CoreData
 */
class PersistentHistoryResult extends PersistentStoreResult
{
    /** @var ArrayClass<ManagedObjectID|PersistentHistoryTransaction|PersistentHistoryChange|Number>|Number The result of the history request determined by the persistent history result type. */
    public readonly ArrayClass|Number $result;

    /**
     * @param ArrayClass<ArrayClass<ManagedObjectID|PersistentHistoryTransaction|PersistentHistoryChange|Number>> $subresults
     * @param PersistentHistoryResultType $resultType The type of result that the persistent history change request returns.
     */
    public function __construct(ArrayClass $subresults, public readonly PersistentHistoryResultType $resultType = PersistentHistoryResultType::statusOnly)
    {
        $sequence = $subresults->joined();
        $this->result = match ($this->resultType) {
            PersistentHistoryResultType::statusOnly => new Number(!$sequence->containsElement(false)),
            PersistentHistoryResultType::count => new Number((int)$sequence->sum()),
            default => new ArrayClass($sequence)
        };
    }
}
