<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 15:46
 */
namespace Sabatier\CoreData;

/**
 * A concrete class used to represent the results of an asynchronous request.
 */
class PersistentStoreAsynchronousResult extends PersistentStoreResult
{
    public function __construct(public readonly ManagedObjectContext $managedObjectContext)
    {
    }

    public function cancel(): void
    {
    }
}
