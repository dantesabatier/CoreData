<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:35
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\URL;

/**
 * An abstract superclass defining the API through which Core Data communicates with a store.
 */
abstract class IncrementalStore extends PersistentStore
{
    #[Override]
    public abstract function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): ?IncrementalStoreNode;

    /**
     * Returns the identifier for the store at a given URL.
     * @param URL $storeURL The URL of a persistent store.
     * @return mixed The identifier for the store at storeURL.
     * @noinspection PhpMixedReturnTypeCanBeReducedInspection
     */
    public static function identifierForNewStore(URL $storeURL): mixed
    {
        return md5($storeURL->absoluteString);
    }
}
