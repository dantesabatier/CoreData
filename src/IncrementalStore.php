<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:35
 */

namespace Sabatier\CoreData;

use InvalidArgumentException;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\URL;

/**
 * An abstract superclass defining the API through which Core Data communicates with a store.
 */
abstract class IncrementalStore extends PersistentStore
{
    /** @var Dictionary<Dictionary<ManagedObjectID>> */
    private Dictionary $cacheEntities;

    public function __construct(PersistentStoreCoordinator $coordinator, string $configurationName, URL $url, ?Dictionary $options = null)
    {
        parent::__construct($coordinator, $configurationName, $url, $options);
        $this->cacheEntities = new Dictionary();
    }

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
    public function newObjectID(EntityDescription $entity, int|string $referenceObject): ManagedObjectID
    {
        $key = (string)$referenceObject;
        /** @var Dictionary<ManagedObjectID> $table */
        $table = $this->cacheEntities[$entity->name] ?? new Dictionary();
        if (!($objectID = $table[$key])) {
            $objectID = new ManagedObjectID($entity, $referenceObject);
            $objectID->persistentStore = $this;
            $table[$key] = $objectID;
            $this->cacheEntities[$entity->name] = $table;
        }
        return $objectID;
    }

    public function obtainPermanentIDs(ArrayClass $objects): ArrayClass
    {
        return $objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID->isTemporaryID ? $this->newObjectID($object->entity, $this->newReferenceObject($object)) : $object->objectID);
    }

    /**
     * Returns the reference data used to construct a given object ID.
     *
     * This method raises an {@see InvalidArgumentException} if the object ID was not created by the receiving store.
     * You should not override this method.
     * @param ManagedObjectID $objectID An object ID created by the receiver.
     * @return int|string The reference data used to construct objectID.
     */
    public function referenceObject(ManagedObjectID $objectID): int|string
    {
        /** @var ManagedObjectID $managedObjectID */
        $managedObjectID = $this->cacheEntities[$objectID->entity->name][(string)$objectID] ?? throw new InvalidArgumentException("Object id wasn't created by this store.");
        return $managedObjectID->referenceObject;
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
