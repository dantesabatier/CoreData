<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 18/06/20
 * Time: 16:01
 */

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\Pure;
use Sabatier\Foundation\ArrayClass;

/**
 * A fetch result object that encompasses the response from an executed asynchronous fetch request.
 */
class AsynchronousFetchResult extends PersistentStoreAsynchronousResult
{
    /**
     * AsynchronousFetchResult constructor.
     * @param AsynchronousFetchRequest $fetchRequest The underlying fetch request that was executed.
     * @param ManagedObjectContext $managedObjectContext
     * @param ArrayClass $finalResult The results that were received from the fetch request.
     */
    #[Pure]
    public function __construct(public readonly AsynchronousFetchRequest $fetchRequest, ManagedObjectContext $managedObjectContext, public readonly ArrayClass $finalResult)
    {
        parent::__construct($managedObjectContext);
    }
}
