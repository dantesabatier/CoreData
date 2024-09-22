<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:35
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\URL;

/**
 * An abstract superclass defining the API through which Core Data communicates with a store.
 */
abstract class IncrementalStore extends PersistentStore
{
    #[Override]
    public function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): ?IncrementalStoreNode
    {
        return null;
    }

    /**
     * Returns a new object ID that uses given data as the key.
     * You should not override this method.
     * @param EntityDescription $entity The entity for the new object ID.
     * @param int|string $referenceObject An object of type string or int to use as the key.
     * @return ManagedObjectID A new object ID for an instance of the entity specified by entity and that uses data as the key.
     */
    final public function newObjectID(EntityDescription $entity, int|string $referenceObject): ManagedObjectID
    {
        return parent::objectID($entity, $referenceObject);
    }

    #[Override]
    public function obtainPermanentIDs(ArrayClass $objects): ArrayClass
    {
        return $objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID->isTemporaryID ? $this->objectID($object->entity, $this->newReferenceObject($object)) : $object->objectID);
    }

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
