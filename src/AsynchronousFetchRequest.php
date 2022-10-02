<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/06/20
 * Time: 20:28
 */

namespace Sabatier\CoreData;

use Closure;
use JetBrains\PhpStorm\Pure;

/**
 * Class AsynchronousFetchRequest
 * A fetch request that retrieves results asynchronously and supports progress notification.
 * @package Sabatier\CoreData
 */
class AsynchronousFetchRequest extends PersistentStoreRequest
{
    /** @var int A configuration parameter that assists Core Data with scheduling the asynchronous fetch request. */
    public int $estimatedResultCount = 0;

    /**
     * Initializes a new asynchronous fetch request configured with the provided fetch request and completion block.
     * @param FetchRequest $fetchRequest The underlying fetch request that is executed asynchronously.
     * @param Closure(AsynchronousFetchResult): void $completionBlock The block that is executed when the fetch request has completed.
     */
    #[Pure]
    public function __construct(public readonly FetchRequest $fetchRequest, public readonly Closure $completionBlock)
    {
        parent::__construct();
    }
}
