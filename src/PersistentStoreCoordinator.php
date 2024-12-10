<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:12
 */

namespace Sabatier\CoreData;

use Closure;
use Exception;
use JetBrains\PhpStorm\Pure;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\NotificationCenter;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\OperationQueue;
use Sabatier\Foundation\URL;
use Throwable;
use function Sabatier\Foundation\fatal_error;
use const Sabatier\Foundation\CocoaErrorDomain;
use const Sabatier\Foundation\LocalizedFailureReasonErrorKey;

/**
 * A coordinator that uses the model to help contexts and persistent stores communicate.
 */
class PersistentStoreCoordinator extends ObjectClass
{
    /** @var Dictionary<class-string<PersistentStore>>|null */
    private static ?Dictionary $registeredStoreTypes = null;
    /** @var ArrayClass<PersistentStore> The persistent stores associated with the coordinator. */
    private(set) ArrayClass $persistentStores {
        get => $this->persistentStores ??= new ArrayClass();
    }
    /** @var string|null Name of the coordinator. */
    public ?string $name = null;
    private OperationQueue $queue {
        get => $this->queue ??= new OperationQueue();
    }

    /**
     * Initializes the coordinator with a managed object model.
     * @param ManagedObjectModel $managedObjectModel A managed object model.
     */
    public function __construct(public readonly ManagedObjectModel $managedObjectModel)
    {
    }

    /**
     * Registers a given PersistentStore subclass for a given store type string.
     *
     * You must invoke this method before a custom subclass of PersistentStore can be loaded into a persistent store coordinator.
     * You can pass nil for storeClass to unregister the store type.
     * @param class-string<PersistentStore>|null $persistentStoreClass The PersistentStore subclass to use for the store of type storeType.
     * @param PersistentStoreType $storeType A unique string that identifies a store type.
     */

    public static function registerStoreClass(?string $persistentStoreClass, PersistentStoreType $storeType): void
    {
        static::registeredStoreTypes()[$storeType->value] = $persistentStoreClass;
    }

    /**
     * Returns a dictionary of the registered store types.
     *
     * A dictionary of the registered store types—the keys are the store type strings, and the values are the {@see PersistentStore} subclasses.
     * @return Dictionary<class-string<PersistentStore>>
     */
    public static function registeredStoreTypes(): Dictionary
    {
        static::$registeredStoreTypes ??= new Dictionary([SQLStoreType => SQLCore::class, XMLStoreType => XMLObjectStore::class, BinaryStoreType => BinaryObjectStore::class, InMemoryStoreType => MemoryObjectStore::class]);
        return static::$registeredStoreTypes;
    }

    /**
     * Returns the persistent store for the specified URL.
     * @param URL $url A URL object that specifies the location of a persistent store.
     * @return PersistentStore|null The persistent store at the location specified by URL.
     */
    public function persistentStore(URL $url): ?PersistentStore
    {
        return $this->persistentStores->first(fn(PersistentStore $store): bool => $store->url->isEqual($url));
    }

    /**
     * Sets the URL for a given persistent store.
     *
     * For atomic stores, this method alters the location to which the next save operation will write the file; for non-atomic stores, invoking this method will relinquish the existing connection and create a new one at the specified URL.
     * (For non-atomic stores, a store must already exist at the destination URL; a new store will not be created.)
     * @param URL $url The new location for store.
     * @param PersistentStore $store A persistent store associated with the receiver.
     * @return bool true if the store was relocated, otherwise false.
     * @throws Exception
     */
    public function setURL(URL $url, PersistentStore $store): bool
    {
        $ok = $store::replacePersistentStoreAtURL($store->url, null, $url, $store->options);
        $store->url = $url;
        return $ok;
    }

    /**
     * Returns the URL for a given persistent store.
     * @param PersistentStore $store A persistent store.
     * @return URL The URL for store.
     */
    #[Pure]
    public function url(PersistentStore $store): URL
    {
        return $store->url;
    }

    /**
     * Returns metadata for a specific type of persistent store at the provided location.
     * @param PersistentStoreType $storeType The store type.
     * @param URL $storeURL The store's location.
     * @param Dictionary|null $options A dictionary containing key-value pairs that specify store behavior and characteristics.
     * @return Dictionary
     * @throws Exception
     */
    public static function metadataForPersistentStore(/** @noinspection PhpUnusedParameterInspection */ PersistentStoreType $storeType, URL $storeURL, ?Dictionary $options = null): Dictionary
    {
        /** @var class-string<PersistentStore> $persistentStoreClass */
        $persistentStoreClass = self::registeredStoreTypes()[$storeType->value];
        return $persistentStoreClass::metadataForPersistentStore($storeURL);
    }

    /**
     * Adds metadata to a specific type of persistent store at the provided location.
     * @param Dictionary|null $metadata A dictionary that contains the metadata to associate with the store.
     * @param PersistentStoreType $storeType The store type.
     * @param URL $storeURL The store's location.
     * @param Dictionary|null $options A dictionary containing key-value pairs that specify store behavior and characteristics. see StoreOptions.php
     * @throws Exception
     */
    public static function setMetadataForPersistentStore(?Dictionary $metadata, PersistentStoreType $storeType, URL $storeURL, ?Dictionary $options = null): void
    {
        if ($options) {
            $metadata?->merge($options);
        }
        /** @var class-string<PersistentStore> $persistentStoreClass */
        $persistentStoreClass = self::registeredStoreTypes()[$storeType->value];
        $persistentStoreClass::setMetadata($metadata, $storeURL);
    }

    /**
     * Returns a dictionary that contains the metadata currently stored and that will be stored in a given persistent store.
     * @param PersistentStore $store A persistent store.
     * @return Dictionary A dictionary that contains the metadata currently stored or to-be-stored in store.
     * @throws Exception
     */
    public function metadata(PersistentStore $store): Dictionary
    {
        return $store->metadata;
    }

    /**
     * Sets the metadata stored in the persistent store during the next save operation executed on it to metadata.
     * @param Dictionary $metadata A dictionary containing metadata for the store.
     * @param PersistentStore $store A persistent store.
     * The store type and UUID ({@see StoreTypeKey} and {@see StoreUUIDKey}) are always added automatically, however {@see StoreUUIDKey} is only added if it is not set manually as part of the dictionary argument.
     * Setting the metadata for a store does not change the information on disk until the store is actually saved.
     */
    public function setMetadata(Dictionary $metadata, PersistentStore $store): void
    {
        $store->metadata = $metadata;
        NotificationCenter::default()->postNotificationName(PersistentStoreCoordinatorStoresDidChange, $this, new Dictionary([UUIDChangedPersistentStoresKey => $store->identifier]));
    }

    /**
     * Adds a new persistent store of a specified type at a given location.
     * @param PersistentStoreType $storeType The store type. For possible values, see {@see PersistentStoreType}.
     * @param string|null $configuration The name of a configuration in the receiver's managed object model that will be used by the new store.
     * The configuration can be nil, in which case no other configurations are allowed.
     * @param URL $storeURL The file location of the persistent store.
     * @param Dictionary|null $options A dictionary containing key-value pairs that specify whether the store should be read-only, and whether (for an XML store) the XML file should be validated against the DTD before it is read.
     * For key definitions, see {@see IgnorePersistentStoreVersioningOption}, {@see MigratePersistentStoresAutomaticallyOption}, {@see InferMappingModelAutomaticallyOption}, {@see ReadOnlyPersistentStoreOption}, {@see ValidateXMLStoreOption}, {@see PersistentStoreTimeoutOption}. This value may be nil.
     * @return PersistentStore
     * @throws Exception If a new store cannot be created, upon return contains an error that describes the problem
     */
    public function addPersistentStoreWithType(PersistentStoreType $storeType, ?string $configuration, URL $storeURL, ?Dictionary $options = null): PersistentStore
    {
        /** @var class-string<PersistentStore> $persistentStoreClass */
        $persistentStoreClass = self::registeredStoreTypes()[$storeType->value] ?? self::registeredStoreTypes()->first(/**
         * @param class-string<PersistentStore> $class
         * @throws Exception
         */ fn(string $class, string $type): bool => $type === $class::metadataForPersistentStore($storeURL)[StoreTypeKey]) ?? fatal_error();
        $persistentStore = new $persistentStoreClass($this, $configuration ?? "Default", $storeURL, $options);
        if (!$persistentStore->load() || !$persistentStore->loadMetadata()) {
            fatal_error();
        }
        $userInfo = new Dictionary([AddedPersistentStoresKey => new ArrayClass([$persistentStore])]);
        NotificationCenter::default()->postNotificationName(PersistentStoreCoordinatorStoresWillChange, $this, $userInfo);
        $this->persistentStores->append($persistentStore);
        $persistentStore->didAdd($this);
        NotificationCenter::default()->postNotificationName(PersistentStoreCoordinatorStoresDidChange, $this, $userInfo);
        if ($options?->valueForKey(MigratePersistentStoresAutomaticallyOption) && !$this->managedObjectModel->isConfigurationCompatibleWithStoreMetadata($configuration, $this->metadata($persistentStore)) && ($store = $this->migratePersistentStore($persistentStore, $storeURL, $options, $storeType))) {
            return $this->addPersistentStoreWithType(PersistentStoreType::from($store->type), $store->configurationName, $store->url, $store->options);
        }
        return $persistentStore;
    }

    /**
     * Creates a persistent store using the provided description and adds it to the coordinator.
     * @param PersistentStoreDescription $description A description object used to create and load a persistent store.
     * @param Closure(PersistentStoreDescription, Error|null): void $completion The completion handler block that's invoked after the store is added.
     * @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection
     */
    public function addPersistentStoreWithDescription(PersistentStoreDescription $description, Closure $completion): void
    {
        $block = function () use ($description, $completion): void {
            try {
                $this->addPersistentStoreWithType(PersistentStoreType::from($description->type), $description->configuration, $description->url, $description->options);
                $completion($description, null);
            } catch (Throwable $throwable) {
                $completion($description, $throwable instanceof InternalInconsistencyException ? $throwable->error : new Error(CocoaErrorDomain, (int)$throwable->getCode(), new Dictionary([LocalizedFailureReasonErrorKey => (string)$throwable])));
            }
        };
        if ($description->shouldAddStoreAsynchronously) {
            $this->performBlock($block);
        } else {
            $block();
        }
    }

    /**
     * Deletes (or truncates) the target persistent store in accordance with the store class' requirements.
     * @param URL $url The location of the store.
     * @param PersistentStoreType $type The type of the persistent store.
     * @param Dictionary|null $options The options of the persistent store.
     * @return bool true on success; otherwise false.
     * @throws Exception
     */
    public function destroyPersistentStoreAtURL(URL $url, PersistentStoreType $type, ?Dictionary $options = null): bool
    {
        /** @var class-string<PersistentStore> $persistentStoreClass */
        $persistentStoreClass = static::registeredStoreTypes()[$type->value];
        return $persistentStoreClass::destroyPersistentStoreAtURL($url, $options);
    }

    /**
     * Moves a persistent store to a new location, changing the storage type if necessary.
     *
     * This method is typically used for "Save As" operations.
     * Performance may vary depending on the type of old and new store.
     * After invocation of this method, the specified store is removed from the coordinator thus store is no longer a useful reference.
     * @param PersistentStore $store A persistent store.
     * @param URL $destinationURL A URL object that specifies the location for the new store.
     * @param Dictionary|null $destinationOptions A dictionary containing key value pairs that specify whether the store should be read only, and whether (for an XML store) the XML file should be validated against the DTD before it is read.
     * @param PersistentStoreType $destinationType The new store type.
     * @return PersistentStore|null If the migration is successful, the new store, otherwise nil.
     * @throws Exception
     */
    public function migratePersistentStore(PersistentStore $store, URL $destinationURL, ?Dictionary $destinationOptions, PersistentStoreType $destinationType): ?PersistentStore
    {
        $sourceURL = $store->url;
        $sourceModel = $store::cachedModelForPersistentStoreWithURL($sourceURL) ?? $this->managedObjectModel;
        $destinationModel = $this->managedObjectModel;
        if ($store->options?->valueForKey(InferMappingModelAutomaticallyOption)) {
            $mappingModel = MappingModel::inferredMappingModel($sourceModel, $destinationModel);
        } else {
            $mappingModel = MappingModel::mappingModel(null, $sourceModel, $destinationModel) ?? fatal_error();
        }
        $sourceType = PersistentStoreType::from($store->type);
        $sourceOptions = $store->options;
        $migrationManagerClass = $store::migrationManagerClass();
        /** @var MigrationManager $migrationManager */
        $migrationManager = new $migrationManagerClass($sourceModel, $destinationModel);
        if ($migrationManager->migrateStore($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions)) {
            $destinationContext = $migrationManager->destinationContext;
            if ($destinationContext->hasChanges) {
                $destinationContext->save();
            }
            $destinationContext->reset();
            $this->remove($store);
            return $destinationContext->persistentStoreCoordinator?->persistentStores->first;
        }
        return null;
    }

    /**
     * Removes a given persistent store.
     * @param PersistentStore $store A persistent store.
     * @return bool true if the store is removed, otherwise false.
     */
    public function remove(PersistentStore $store): bool
    {
        $userInfo = new Dictionary([RemovedPersistentStoresKey => new ArrayClass([$store])]);
        $notificationCenter = NotificationCenter::default();
        $notificationCenter->postNotificationName(PersistentStoreCoordinatorStoresWillChange, $this, $userInfo);
        $notificationCenter->postNotificationName(PersistentStoreCoordinatorWillRemoveStore, $this, $userInfo);
        $store->willRemove($this);
        $this->persistentStores->remove($store);
        $notificationCenter->postNotificationName(PersistentStoreCoordinatorStoresDidChange, $this, $userInfo);
        return true;
    }

    /**
     * Replace the destination persistent store with the source store.
     * @param URL $destinationURL A URL object that specifies the location for the new store.
     * @param Dictionary|null $destinationOptions A dictionary containing key value pairs that specify whether the store should be read only, and whether (for an XML store) the XML file should be validated against the DTD before it is read.
     * @param URL $sourceURL A URL object that specifies the location of a persistent store.
     * @param Dictionary|null $sourceOptions A dictionary.
     * @param PersistentStoreType $storeType The store type of the replacement store.
     */
    public function replacePersistentStore(URL $destinationURL, ?Dictionary $destinationOptions, URL $sourceURL, ?Dictionary $sourceOptions, PersistentStoreType $storeType): void
    {
        /** @var class-string<PersistentStore> $persistentStoreClass */
        $persistentStoreClass = static::registeredStoreTypes()[$storeType->value];
        $persistentStoreClass::replacePersistentStoreAtURL($destinationURL, $destinationOptions, $sourceURL, $sourceOptions);
    }

    /**
     * Sends a request to all the persistent stores associated with the coordinator.
     * @param PersistentStoreRequest $request A fetch or save request.
     * @param ManagedObjectContext $context The context against which request should be executed.
     * @return ArrayClass<ManagedObject|ManagedObjectID|Dictionary|Number>|ArrayClass<ArrayClass<ManagedObject|ManagedObjectID|Dictionary|Number>> An array containing managed objects, managed object IDs, or dictionaries as appropriate for a fetch request; an empty array if request is a save request, or nil if an error occurred.
     * User defined requests return arrays of arrays, where a nested array is the result returned from a single store.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function execute(PersistentStoreRequest $request, ManagedObjectContext $context): ArrayClass
    {
        $stores = $request->affectedStores ?? $this->persistentStores;
        $result = $stores->map(fn(PersistentStore $store): ArrayClass => $store->execute($request, $context));
        if ($request instanceof FetchRequest || $request instanceof SaveChangesRequest) {
            return new ArrayClass($result->joined());
        }
        return $result;
    }

    /**
     * Asynchronously performs the block on the coordinator's queue.
     * @param Closure(): void $block
     */
    public function performBlock(Closure $block): void
    {
        $this->queue->addOperationWithBlock($block);
    }

    /**
     * Synchronously performs the block on the coordinator's queue.
     * @param Closure(): void $block
     */
    public function performBlockAndWait(Closure $block): void
    {
        $this->queue->addOperationWithBlock($block);
    }

    /**
     * Returns a single persistent history token for the specified persistent stores.
     *
     * If stores is nil or an empty array, constructs a persistent history token with all the persistent stores in the coordinator.
     * @param ArrayClass<PersistentStore>|null $stores
     * @return PersistentHistoryToken|null
     */
    public function currentPersistentHistoryToken(?ArrayClass $stores = null): ?PersistentHistoryToken
    {
        $stores ??= $this->persistentStores;
        if ($stores->isEmpty) {
            return null;
        }
        return new PersistentHistoryToken($stores->reduce(new Dictionary(), function (Dictionary &$result, PersistentStore $store): Dictionary {
            $result[$store->configurationName] = $store;
            return $result;
        }));
    }

    /**
     * Returns an object ID for the specified URI representation of an object ID if a matching store is available, or nil if a matching store cannot be found.
     * @param URL $uriRepresentation A URL object containing a URI that specify a managed object.
     * @return ManagedObjectID|null An object ID for the object specified by URL.
     * The URI representation contains a UUID of the store the ID is coming from, and the coordinator can match it against the stores added to it.
     * @throws Exception
     */
    public function managedObjectID(URL $uriRepresentation): ?ManagedObjectID
    {
        if (($entity = $this->managedObjectModel->entitiesByName[$uriRepresentation->deletingLastPathComponent()->lastPathComponent]) && ($store = $this->persistentStores->first(fn(PersistentStore $store): bool => $store->identifier === $uriRepresentation->host))) {
            return $store->objectID($entity, $uriRepresentation->lastPathComponent);
        }
        return null;
    }

    /**
     * @internal
     */
    public function persistentStoreForObjectID(ManagedObjectID $objectID): PersistentStore
    {
        return $objectID->persistentStore ?? $this->persistentStores->first(fn(PersistentStore $store): bool => ($this->managedObjectModel->entities($store->configurationName)?->contains(fn(EntityDescription $entity): bool => $entity->isKindOf($objectID->entity))) ?? false) ?? $this->persistentStores[0];
    }

    /**
     * @internal
     */
    public function persistentStoreForObject(ManagedObject $object): PersistentStore
    {
        return $this->persistentStoreForObjectID($object->objectID);
    }
}
