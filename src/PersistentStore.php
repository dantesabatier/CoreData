<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\request_concrete_implementation;

/**
 * The abstract base class for all Core Data persistent stores.
 * @psalm-consistent-constructor
 */
abstract class PersistentStore extends ObjectClass
{
    /** @var class-string<MigrationManager> The class responsible for managing schema migrations. This class is instantiated when the persistent store requires a migration to match the current managed object model. */
    public static string $migrationManagerClass = MigrationManager::class;
    /** @var class-string<RowCache> The class that provides the L2 caching mechanism for the store. This determines the persistence strategy for snapshots and relationship results (e.g., APCu, or Redis). The default value is {@see DefaultRowCache}. */
    public static string $rowCacheClass = DefaultRowCache::class;
    /** @var class-string<SnapshotMapper> The class used to map raw data from the persistent store into managed object snapshots. It handles the conversion between primitive store values and the dictionary format used by the framework. */
    public static string $snapshotMapperClass = StandardSnapshotMapper::class;
    /** @var string The type string of the persistent store. */
    abstract public string $type {
        get;
    }
    /** @var string The unique identifier for the persistent store. */
    public string $identifier {
        get => $this->identifier ??= $this->options?->valueForKey(PersistentStoreIDOption) ?? md5($this->url->absoluteString);
    }
    /** @var int The time interval, in seconds, for which cached snapshots and relationships remain valid. */
    public int $stalenessInterval {
        get => $this->stalenessInterval ??= (int)($this->options?->valueForKey(PersistentStoreCacheStalenessIntervalOption) ?? SecondsPerHourTimeInterval);
    }
    /** @var Dictionary<mixed> The metadata for the persistent store. The dictionary must include the store type. */
    public Dictionary $metadata {
        get => $this->metadata ??= new Dictionary([StoreTypeKey => $this->type, StoreUUIDKey => $this->identifier]);
    }
    /** @var bool A Boolean value that indicates whether the persistent store is read-only. */
    public bool $isReadOnly = false;
    /** @internal */
    public FaultHandler $faultHandler {
        get => $this->faultHandler ??= new FaultHandler($this);
    }
    /** @var Dictionary<Dictionary<ManagedObjectID>> */
    private Dictionary $cacheEntities {
        get => $this->cacheEntities ??= new Dictionary();
    }
    public PersistentStoreCache $rowCache {
        get => $this->rowCache ??= new (static::$rowCacheClass)();
    }

    /**
     * Returns a store initialized with the given arguments.
     *
     * You must ensure that you load metadata during initialization and set it using {@see $metadata}.
     * @param PersistentStoreCoordinator $persistentStoreCoordinator A persistent store coordinator.
     * @param string $configurationName The name of the managed object model configuration to use.
     * @param URL $url The URL of the store to load.
     * @param Dictionary<mixed>|null $options A dictionary containing configuration options.
     * @see PersistentStoreCoordinator for a list of key names for options in this dictionary.
     */
    public function __construct(public readonly PersistentStoreCoordinator $persistentStoreCoordinator, public readonly string $configurationName, public URL $url, private(set) ?Dictionary $options = null {
        set {
            $this->options = $value;
            $this->isReadOnly = (bool)$value?->valueForKey(ReadOnlyPersistentStoreOption);
        }
    })
    {
    }

    /**
     * @param URL $url
     * @param Dictionary<mixed>|null $options
     * @return ManagedObjectModel|null
     * @internal
     */
    public static function cachedModelForPersistentStoreWithURL(/** @noinspection PhpUnusedParameterInspection */ URL $url, ?Dictionary $options = null): ?ManagedObjectModel
    {
        return null;
    }

    /**
     * @param URL $url
     * @param Dictionary<mixed>|null $options
     * @return bool
     * @throws Exception
     * @internal
     */
    public static function destroyPersistentStoreAtURL(URL $url, ?Dictionary $options = null): bool
    {
        if (!FileManager::default()->fileExists($url->path)) {
            return true;
        }
        return FileManager::default()->removeItem($url);
    }

    /**
     * @param URL $destinationURL
     * @param Dictionary<mixed>|null $destinationOptions
     * @param URL $sourceURL
     * @param Dictionary<mixed>|null $sourceOptions
     * @return bool
     * @throws Exception
     * @internal
     */
    public static function replacePersistentStoreAtURL(URL $destinationURL, ?Dictionary $destinationOptions, URL $sourceURL, ?Dictionary $sourceOptions): bool
    {
        return FileManager::default()->moveItem($sourceURL, $destinationURL);
    }

    /**
     * Returns a value as appropriate for the given request, or null if the request cannot be completed.
     * @param PersistentStoreRequest $request A fetch request.
     * @param ManagedObjectContext $context The managed object context used to execute $request.
     * @return ArrayClass<ManagedObject|ManagedObjectID|Dictionary|Number> A value as appropriate for $request.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function execute(PersistentStoreRequest $request, ManagedObjectContext $context): ArrayClass
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /** @internal */
    public function managedObjectID(URL $uriRepresentation): ManagedObjectID
    {
        $referenceObject = (int)$uriRepresentation->lastPathComponent;
        $entityName = $uriRepresentation->deletingLastPathComponent()->lastPathComponent;
        /** @var EntityDescription $entity */
        $entity = $this->persistentStoreCoordinator->managedObjectModel->entitiesByName[$entityName] ?? fatal_error(sprintf("Failed to resolve EntityDescription from URI: entity \"%s\" not found in managedObjectModel", $entityName));
        $host = $uriRepresentation->host ?? "";
        $this->identifier === $host ?: fatal_error(sprintf("ManagedObjectID host mismatch: expected \"%s\", got \"%s\"", $this->identifier, $host));
        return $this->objectID($entity, $referenceObject);
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
     * @return mixed A store node encapsulating the persistent external values of the object with object ID objectID, or null if the corresponding object cannot be found.
     * The returned node should include all attributes values and may include to-one relationship values as instances of ManagedObjectID.
     * If an object with object ID objectID cannot be found, the method should return null.
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
     * @return ArrayClass<ManagedObjectID>|ManagedObjectID|Nil The value of the relationship specified relationship of the object with object ID objectID, or null if an error occurs.
     * If the relationship is a to-one, the method should return a {@see ManagedObjectID} instance that identifies the destination, or null if the relationship value is null.
     * If the relationship is to many, the method should return a collection object containing {@see ManagedObjectID} instances to identify the related objects.
     * Using an array instance is preferred because it will be the most efficient.
     * A store may also return an instance of {@see Set}; an instance of Dictionary is not acceptable.
     * If an object with object ID objectID cannot be found, the method should return null.
     * @throws Exception
     */
    public function newValueForRelationship(/** @noinspection PhpUnusedParameterInspection */ RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass|ManagedObjectID|Nil
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * @param RelationshipDescription $relationship
     * @param ManagedObjectID $objectID
     * @param ManagedObjectContext $context
     * @return ArrayClass<ManagedObjectID>|Nil
     * @throws Exception
     */
    public function newOrderedRelationshipInformationForRelationship(/** @noinspection PhpUnusedParameterInspection */ RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass|Nil
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * @param FetchedPropertyDescription $fetchedProperty
     * @param ManagedObjectID $objectID
     * @param ManagedObjectContext $context
     * @return ArrayClass<ManagedObjectID>
     * @throws Exception
     */
    public function newValueForFetchedProperty(/** @noinspection PhpUnusedParameterInspection */ FetchedPropertyDescription $fetchedProperty, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * @param ArrayClass<ManagedObjectID> $objectIDs
     * @param QueryGenerationToken|null $generation
     * @internal
     */
    public function managedObjectContextDidRegisterObjectsWithIDs(ArrayClass $objectIDs, ?QueryGenerationToken $generation): void
    {
    }

    /**
     * @param ArrayClass<ManagedObjectID> $objectIDs
     * @param QueryGenerationToken|null $generation
     * @internal
     */
    public function managedObjectContextDidUnregisterObjectsWithIDs(ArrayClass $objectIDs, ?QueryGenerationToken $generation): void
    {
    }

    /**
     * Returns an array containing the object IDs for a given array of newly inserted objects.
     *
     * The returned array must return the object IDs in the same order as the objects appear in $objects.
     * This method is called before {@see execute()} with a save request, to assign permanent IDs to newly inserted objects.
     * @param ArrayClass<ManagedObject> $objects An array of newly inserted objects.
     * @return ArrayClass<ManagedObjectID> An array containing the object IDs for the objects in $objects.
     */
    public function obtainPermanentIDs(ArrayClass $objects): ArrayClass
    {
        return $objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID->isTemporaryID ? $this->objectID($object->entity, $this->newReferenceObject($object)) : $object->objectID);
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
     * This method is invoked by the framework after a save operation on a managed object context, once for each newly inserted managed object.
     * The value returned is used to create a permanent ID for the object and must be unique for an instance within its entity's inheritance hierarchy (in this store).
     * You must override this method.
     * This method must return a stable (unchanging) value for a given object, otherwise Save As and migration will not work correctly.
     * This means that you can use arbitrary numbers, UUIDs, or other random values only if they are persisted with the raw data.
     * If you cannot save the originally assigned reference object with the data, then the method must derive the reference object from the managed object's values.
     * @param ManagedObject $managedObject A managed object. At the time this method is called, it has a temporary ID.
     * @return int|string A new reference object for managedObject.
     */
    public function newReferenceObject(ManagedObject $managedObject): int|string
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns the metadata from the persistent store at the given URL.
     * Subclasses must override this method.
     * @param URL $url The location of the store.
     * @return Dictionary<mixed> The metadata from the persistent store at url.
     * @throws Exception If an error occurs, upon return contains an error that describes the problem.
     */
    public static function metadataForPersistentStore(/** @noinspection PhpUnusedParameterInspection */ URL $url): Dictionary
    {
        request_concrete_implementation(static::class, __FUNCTION__);
    }

    /**
     * Sets the metadata for the store at a given URL.
     * Subclasses must override this method to set metadata appropriately.
     * @param Dictionary<mixed>|null $metadata The metadata for the store at url.
     * @param URL $url The location of the store.
     * @return bool true if the metadata was written correctly, otherwise false.
     * @throws Exception
     */
    public static function setMetadata(?Dictionary $metadata, URL $url): bool
    {
        request_concrete_implementation(static::class, __FUNCTION__);
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
     * You can override this method in a subclass to perform any kind of setup necessary before the load method is invoked.
     * @param PersistentStoreCoordinator $coordinator The persistent store coordinator to which the receiver was added.
     */
    public function didAdd(PersistentStoreCoordinator $coordinator): void
    {
    }

    /**
     * Invoked before the persistent store is removed from the persistent store coordinator.
     *
     * The default implementation does nothing.
     * You can override this method in a subclass to perform any cleanup before the store is removed from the coordinator (and deallocated).
     * @param PersistentStoreCoordinator $coordinator The persistent store coordinator from which the receiver was removed.
     */
    public function willRemove(PersistentStoreCoordinator $coordinator): void
    {
    }

    /**
     * @throws Exception
     */
    public function load(): bool
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * @throws Exception
     * @internal
     */
    public function unload(): bool
    {
        return true;
    }
}
