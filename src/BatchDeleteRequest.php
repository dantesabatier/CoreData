<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 16:25
 */

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\Pure;

/**
 * A request that deletes objects in the SQL persistent store without loading them into memory.
 */
final class BatchDeleteRequest extends PersistentStoreRequest
{
    /** @var BatchDeleteRequestResultType The type of result the request provides when it executes. */
    public BatchDeleteRequestResultType $resultType = BatchDeleteRequestResultType::statusOnly;

    /**
     * Creates a request that deletes the results of the specified fetch request.
     * @param FetchRequest $fetchRequest The fetch request that identifies the managed objects to delete.
     */
    #[Pure]
    public function __construct(public readonly FetchRequest $fetchRequest)
    {
        parent::__construct(PersistentStoreRequestType::batchDeleteRequestType);
    }
}
