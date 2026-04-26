<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 16:07
 */
namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;

/**
 * The result returned when executing a batch delete request.
 */
final class BatchDeleteResult extends PersistentStoreResult
{
    /** @var ArrayClass<ManagedObjectID>|Number The result of a batch-deletion request, either the number of deleted objects, the identifiers of the deleted objects, or a status value. */
    public readonly ArrayClass|Number $result;

    /**
     * BatchDeleteResult constructor.
     * @param ArrayClass<ArrayClass<ManagedObjectID|Number>> $subresults
     * @param BatchDeleteRequestResultType $resultType
     */
    public function __construct(ArrayClass $subresults, public readonly BatchDeleteRequestResultType $resultType = BatchDeleteRequestResultType::statusOnly)
    {
        $sequence = $subresults->joined();
        /** @psalm-suppress PossiblyInvalidPropertyAssignmentValue */
        $this->result = match ($this->resultType) {
            BatchDeleteRequestResultType::statusOnly => new Number(!$sequence->containsElement(false)),
            BatchDeleteRequestResultType::count => new Number($sequence->sum()),
            default => new ArrayClass($sequence)
        };
    }
}
