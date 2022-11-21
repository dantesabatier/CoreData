<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 16:29
 */

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\Pure;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;

/**
 * A request to Core Data to do a batch update of data in a persistent store without loading any data into memory.
 */
class BatchUpdateRequest extends PersistentStoreRequest
{
    /** @var Predicate|null A predicate that identifies the objects to update. */
    public ?Predicate $predicate = null;
    /** @var Dictionary|null A dictionary of property description pairs that describe the updates. The dictionary keys are either {@see PropertyDescription} objects or strings that identify the property name. The dictionary values are either a constant value or an {@see Expression} that evaluates to a scalar value. */
    public ?Dictionary $propertiesToUpdate = null;
    /** @var bool A Boolean value that indicates whether to update subentities. */
    public bool $includesSubentities = true;
    /** @var BatchUpdateRequestResultType The type of result that Core Data returns from the request. */
    public BatchUpdateRequestResultType $resultType = BatchUpdateRequestResultType::statusOnly;

    #[Pure]
    /**
     * Creates a batch-update request for a managed entity.
     * @param EntityDescription $entity The managed entity to update data for.
     */
    public function __construct(public readonly EntityDescription $entity)
    {
        parent::__construct(PersistentStoreRequestType::batchUpdateRequestType);
    }
}
