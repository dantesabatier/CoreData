<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:35
 */
namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\request_concrete_implementation;

/**
 * An abstract superclass defining the API through which Core Data communicates with a store.
 */
abstract class IncrementalStore extends PersistentStore
{
    #[Override]
    public function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): IncrementalStoreNode
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns the identifier for the store at a given URL.
     * @param URL $storeURL The URL of a persistent store.
     * @return string The identifier for the store at storeURL.
     */
    public static function identifierForNewStore(URL $storeURL): string
    {
        return md5($storeURL->absoluteString);
    }
}
