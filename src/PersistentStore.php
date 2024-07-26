<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\request_concrete_implementation;
use function Sabatier\Foundation\uuid_generate;

/**
 * The abstract base class for all Core Data persistent stores.
 * @psalm-consistent-constructor
 */
abstract class PersistentStore extends ObjectClass
{
    /** @var string The type string of the persistent store. */
    public string $type;
    /** @var string The unique identifier for the persistent store. */
    public string $identifier;
    /** @var Dictionary The metadata for the persistent store. The dictionary must include the store type. */
    public Dictionary $metadata;
    /** @var bool A Boolean value that indicates whether the persistent store is read-only. */
    public bool $isReadOnly = false;
    /** @internal */
    public readonly FaultHandler $faultHandler;
    /** @var Dictionary<Dictionary<ManagedObjectID>> */
    private Dictionary $cacheEntities;

    /**
     * Returns a store initialized with the given arguments.
     *
     * You must ensure that you load metadata during initialization and set it using {@see $metadata}.
     * @param PersistentStoreCoordinator $persistentStoreCoordinator A persistent store coordinator.
     * @param string $configurationName The name of the managed object model configuration to use.
     * @param URL $url The URL of the store to load.
     * @param Dictionary|null $options A dictionary containing configuration options.
     * @see PersistentStoreCoordinator for a list of key names for options in this dictionary.
     */
    public function __construct(public readonly PersistentStoreCoordinator $persistentStoreCoordinator, public readonly string $configurationName, public URL $url, public readonly ?Dictionary $options = null)
    {
        unset($this->identifier);
        unset($this->metadata);
        unset($this->isReadOnly);
        unset($this->faultHandler);
        unset($this->cacheEntities);
    }

    public function __get(string $name)
    {
        return $this->$name = match ($name) {
            "identifier" => uuid_generate(),
            "metadata" => new Dictionary([StoreTypeKey => $this->type, StoreUUIDKey => $this->identifier]),
            "isReadOnly" => (bool)$this->options?->valueForKey(ReadOnlyPersistentStoreOption),
            "faultHandler" => new FaultHandler($this),
            "cacheEntities" => new Dictionary(),
            default => $this->valueForUndefinedKey($name)
        };
    }

    /**
     * @internal
     */
    public static function cachedModelForPersistentStoreWithURL(/** @noinspection PhpUnusedParameterInspection */ URL $url, ?Dictionary $options = null): ?ManagedObjectModel
    {
        return null;
    }

    /**
     * @internal
     */
    public static function destroyPersistentStoreAtURL(URL $url, ?Dictionary $options = null): bool
    {
        if (!FileManager::default()->fileExists($url->path)) {
            return true;
        }
        try {
            return FileManager::default()->removeItem($url);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @internal
     */
    public static function replacePersistentStoreAtURL(URL $destinationURL, ?Dictionary $destinationOptions, URL $sourceURL, ?Dictionary $sourceOptions): bool
    {
        try {
            return FileManager::default()->moveItem($sourceURL, $destinationURL);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Returns a value as appropriate for the given request, or nil if the request cannot be completed.
     * @param PersistentStoreRequest $request A fetch request.
     * @param ManagedObjectContext $context The managed object context used to execute request.
     * @return ArrayClass<ManagedObject|ManagedObjectID|Dictionary|Number> A value as appropriate for request.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     * @psalm-suppress InvalidReturnType
     */
    public function execute(PersistentStoreRequest $request, ManagedObjectContext $context): ArrayClass
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns a managed object ID from the reference data for a specified entity.
     *
     * You use this method to create managed object IDs which are then used to create cache nodes for information being loaded into the store.
     * You should not override this method.
     * @param EntityDescription $entity An entity description object.
     * @param int|string $referenceObject Reference data for which the managed object ID is required.
     * @return ManagedObjectID The managed object ID from the reference data for a specified entity
     */
    final public function objectID(EntityDescription $entity, int|string $referenceObject): ManagedObjectID
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

    /**
     * Returns a store node encapsulating the persistent external values of the object with a given object ID.
     * @param ManagedObjectID $objectID The ID of the object for which values are requested.
     * @param ManagedObjectContext $context The managed object context into which values will be returned.
     * @return mixed A store node encapsulating the persistent external values of the object with object ID objectID, or nil if the corresponding object cannot be found.
     * The returned node should include all attributes values and may include to-one relationship values as instances of ManagedObjectID.
     * If an object with object ID objectID cannot be found, the method should return nil and if error is not NULL create and return an appropriate error object in error.
     * @throws Exception
     */
    public function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): mixed
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns the relationship for the given relationship of the object with a given object ID.
     * @param RelationshipDescription $relationship The relationship for which values are requested.
     * @param ManagedObjectID $objectID The ID of the object for which values are requested.
     * @param ManagedObjectContext $context The managed object context into which values will be returned.
     * @return mixed The value of the relationship specified relationship of the object with object ID objectID, or nil if an error occurs.
     * If the relationship is a to-one, the method should return a {@see ManagedObjectID} instance that identifies the destination, or null if the relationship value is nil.
     * If the relationship is a to-many, the method should return a collection object containing {@see ManagedObjectID} instances to identify the related objects.
     * Using an array instance is preferred because it will be the most efficient.
     * A store may also return an instance of {@see Set}; an instance of Dictionary is not acceptable.
     * If an object with object ID objectID cannot be found, the method should return nil and if error is not null create and return an appropriate error object in error.
     * @throws Exception
     */
    public function newValueForRelationship(/** @noinspection PhpUnusedParameterInspection */ RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): mixed
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns an array containing the object IDs for a given array of newly inserted objects.
     *
     * The returned array must return the object IDs in the same order as the objects appear in array.
     * This method is called before {@see execute()} with a save request, to assign permanent IDs to newly inserted objects.
     * @param ArrayClass<ManagedObject> $objects An array of newly inserted objects.
     * @return ArrayClass<ManagedObjectID> An array containing the object IDs for the objects in array.
     * @psalm-suppress InvalidReturnType
     */
    public function obtainPermanentIDs(ArrayClass $objects): ArrayClass
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns the reference object for a given managed object ID.
     *
     * Subclasses should invoke this method to extract the reference data from the object ID for each cache node if the data is to be made persistent.
     * @param ManagedObjectID $objectID A managed object ID.
     * @return int|string The reference object for objectID.
     */
    final public function referenceObject(ManagedObjectID $objectID): int|string
    {
        /** @var ManagedObjectID $managedObjectID */
        $managedObjectID = $this->cacheEntities[$objectID->entity->name][(string)$objectID] ?? fatal_error("Object id wasn't created by this store.");
        return $managedObjectID->referenceObject;
    }

    /**
     * Returns a new reference object for a given managed object.
     *
     * This method is invoked by the framework after a save operation on a managed object context, once for each newly-inserted managed object.
     * The value returned is used to create a permanent ID for the object and must be unique for an instance within its entity's inheritance hierarchy (in this store).
     * You must override this method.
     * This method must return a stable (unchanging) value for a given object, otherwise Save As and migration will not work correctly.
     * This means that you can use arbitrary numbers, UUIDs, or other random values only if they are persisted with the raw data.
     * If you cannot save the originally-assigned reference object with the data, then the method must derive the reference object from the managed object's values.
     * @param ManagedObject $managedObject A managed object. At the time this method is called, it has a temporary ID.
     * @return int|string A new reference object for managedObject.
     */
    public function newReferenceObject(/** @noinspection PhpUnusedParameterInspection */ ManagedObject $managedObject): int|string
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns the metadata from the persistent store at the given URL.
     * Subclasses must override this method.
     * @param URL $url The location of the store.
     * @return Dictionary The metadata from the persistent store at url.
     * @throws Exception If an error occurs, upon return contains an error that describes the problem.
     */
    public static function metadataForPersistentStore(/** @noinspection PhpUnusedParameterInspection */ URL $url): Dictionary
    {
        request_concrete_implementation(self::class, __FUNCTION__);
    }

    /**
     * Sets the metadata for the store at a given URL.
     * Subclasses must override this method to set metadata appropriately.
     * @param Dictionary|null $metadata The metadata for the store at url.
     * @param URL $url The location of the store.
     * @return bool true if the metadata was written correctly, otherwise false.
     * @throws Exception
     */
    public static function setMetadata(/** @noinspection PhpUnusedParameterInspection */ ?Dictionary $metadata, URL $url): bool
    {
        request_concrete_implementation(self::class, __FUNCTION__);
    }

    /**
     * Instructs the persistent store to load its metadata.
     * There is no way to return an error if the store is invalid.
     * @return bool true if the metadata was loaded correctly, otherwise false.
     * @throws Exception
     */
    public function loadMetadata(): bool
    {
        return true;
    }

    /**
     * Invoked after the persistent store has been added to the persistent store coordinator.
     *
     * The default implementation does nothing.
     * You can override this method in a subclass in order to perform any kind of setup necessary before the load method is invoked.
     * @param PersistentStoreCoordinator $coordinator The persistent store coordinator to which the receiver was added.
     */
    public function didAdd(PersistentStoreCoordinator $coordinator): void
    {
    }

    /**
     * Invoked before the persistent store is removed from the persistent store coordinator.
     *
     * The default implementation does nothing.
     * You can override this method in a subclass in order to perform any clean-up before the store is removed from the coordinator (and deallocated).
     * @param PersistentStoreCoordinator $coordinator The persistent store coordinator from which the receiver was removed.
     */
    public function willRemove(PersistentStoreCoordinator $coordinator): void
    {
    }

    /**
     * Returns the migration manager class for this store class.
     *
     * In a subclass of PersistentStore, you can override this to provide a custom migration manager subclass
     * (for example, to take advantage of store-specific functionality to improve migration performance).
     * @return class-string<MigrationManager> The {@see MigrationManager} class for this store class
     */
    public static function migrationManagerClass(): string
    {
        return MigrationManager::class;
    }

    /**
     * @throws Exception
     */
    public function load(): bool
    {
        request_concrete_implementation($this, __FUNCTION__);
    }
}
