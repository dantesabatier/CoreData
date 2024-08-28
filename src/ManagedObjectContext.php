<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:14
 */

namespace Sabatier\CoreData;

use Closure;
use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyValueChange;
use Sabatier\Foundation\KeyValueObservedChange;
use Sabatier\Foundation\KeyValueObservingOptions;
use Sabatier\Foundation\Notification;
use Sabatier\Foundation\NotificationCenter;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\OperationQueue;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\UndoManager;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\typeof;

/**
 * An object space that you use to manipulate and track changes to managed objects.
 *
 * A context consists of a group of related model objects that represent an internally consistent view of one or more persistent stores. Changes to managed objects remain in memory in the associated context until Core Data saves that context to one or more persistent stores. A single managed object instance exists in one and only one context, but multiple copies of an object can exist in different contexts. Therefore, an object is unique to a particular context.
 * @property-read Set<ManagedObject> $registeredObjects The set of objects registered with the context.
 */
class ManagedObjectContext extends ObjectClass
{
    private const string observationContext = "observationContext";
    final public const string didChangeObjectsNotification = ManagedObjectContextObjectsDidChange;
    final public const string willSaveObjectsNotification = ManagedObjectContextWillSave;
    final public const string didSaveObjectsNotification = ManagedObjectContextDidSave;
    final public const string didSaveObjectIDsNotification = ManagedObjectContextDidSaveObjectIDs;
    /** @var PersistentStoreCoordinator|null The persistent store coordinator of the context.
     * The coordinator provides the managed object model and handles persistence.
     * Note that multiple contexts can share a coordinator. May not be nil. */
    public ?PersistentStoreCoordinator $persistentStoreCoordinator = null;
    /** @var ManagedObjectContext|null The parent of the context. */
    public ?ManagedObjectContext $parent = null;
    /** @var string|null The developer-provided name of the context. */
    public ?string $name = null;
    /** @var Dictionary The user information for the context. */
    public readonly Dictionary $userInfo;
    /** @var Set<IncrementalStoreNode> */
    private readonly Set $unprocessedChanges;
    /** @var Set<IncrementalStoreNode> */
    private readonly Set $unprocessedDeletes;
    /** @var Set<IncrementalStoreNode> */
    private readonly Set $unprocessedInserts;
    /** @var Dictionary<ManagedObject> */
    private Dictionary $byHashAssociationTable;
    /** @var bool A Boolean value that indicates whether the context keeps strong references to all registered managed objects. If set to true, the receiver keeps strong references to all registered managed objects. If set to false, then the receiver keeps strong references to registered objects only when they are inserted, updated, deleted, or locked. The default is false. */
    public bool $retainsRegisteredObjects = false;
    /** @var bool A Boolean value that determines whether the context turns inaccessible faults into deleted objects. Use this property to control how the context behaves when it encounters an inaccessible fault  an object with no underlying data in the persistent store. For example, you might fetch an object that has a to-many relationship, but then a background context deletes the related objects from the store before you traverse that relationship. */
    public bool $shouldDeleteInaccessibleFaults = true;
    /** @var Set<ManagedObject> The set of objects that have been inserted into the context but not yet saved in a persistent store. */
    public readonly Set $insertedObjects;
    /** @var Set<ManagedObject> The set of objects registered with the context that have uncommitted changes. */
    public readonly Set $updatedObjects;
    /** @var Set<ManagedObject> The set of objects that will be removed from their persistent store during the next save operation. */
    public readonly Set $deletedObjects;
    /** @var Set<ManagedObject> */
    public readonly Set $lockedObjects;
    /** @var Set<ManagedObject> $refreshedObjects */
    private readonly Set $refreshedObjects;
    private bool $processingChanges = false;
    private bool $savingInProgress = false;
    /** @var bool A Boolean value that indicates whether the context automatically merges changes saved to its persistent store coordinator or parent context. */
    public bool $automaticallyMergesChangesFromParent = true;
    /** @var MergePolicy The merge policy of the context. */
    public MergePolicy $mergePolicy;
    /** @var QueryGenerationToken|null Returns the token associated with the query generation currently in use by this context. */
    public readonly ?QueryGenerationToken $queryGenerationToken;
    /** @var string|null The author for the context that is used as an identifier in persistent history transactions. Set a managed object context's transactionAuthor before saving it to differentiate among multiple call sites that modify the same context. Doing this records an author in subsequent transactions. */
    public ?string $transactionAuthor = null;
    /** @var bool A Boolean value that indicates whether the context has uncommitted changes. */
    public bool $hasChanges = false;
    /** @var bool A Boolean value that indicates whether the context propagates deletes at the end of the event in which a change was made.
     * true if the receiver propagates deletes at the end of the event in which a change was made, false if it propagates deletes only during a save operation. The default is true. */
    public bool $propagatesDeletesAtEndOfEvent = true;
    /** @var UndoManager|null The object that provides undo support for the context. Enable undo support for a context by setting this property to an instance of UndoManager. This can be an undo manager that’s exclusive to the context, or an existing undo manager if you want to integrate the context’s undo operations with those of the rest of your app. If your context uses an undo manager, you can realize a performance benefit by temporarily setting this property to nil when performing expensive operations on that context, such as importing a large number of objects. */
    public ?UndoManager $undoManager = null;
    /** @var float The maximum length of time that may have elapsed since the store previously fetched data before fulfilling a fault issues a new fetch. The staleness interval controls whether fulfilling a fault uses data previously fetched by the application, or issues a new fetch (see also {@see refresh()}). The staleness interval does not affect objects currently in use (that is, it is not used to automatically update property values from a persistent store after a certain period of time).
     * The expiration value is applied on a per-object basis. It is the relative time until cached data (snapshots) should be considered stale. For example, a value of 300.0 informs the context to utilize cached information for no more than 5 minutes after an object was originally fetched.
     * Note that the staleness interval is a hint and may not be supported by all persistent store types. It is not used by XML and binary stores, because these stores maintain all current values in memory.
     * The default is a negative value, which represents infinite staleness allowed. 0.0 represents "no staleness acceptable". */
    public float $stalenessInterval = -1.0;
    private OperationQueue $queue;

    /**
     * Initializes a context with a given concurrency type.
     * @param ManagedObjectContextConcurrencyType $concurrencyType The concurrency pattern with which context will be used.
     */
    public function __construct(public readonly ManagedObjectContextConcurrencyType $concurrencyType = ManagedObjectContextConcurrencyType::mainQueueConcurrencyType)
    {
        unset($this->persistentStoreCoordinator);
        unset($this->queryGenerationToken);
        unset($this->queue);
        unset($this->mergePolicy);
        unset($this->userInfo);
        unset($this->byHashAssociationTable);
        unset($this->unprocessedChanges);
        unset($this->unprocessedDeletes);
        unset($this->unprocessedInserts);
        unset($this->insertedObjects);
        unset($this->updatedObjects);
        unset($this->deletedObjects);
        unset($this->lockedObjects);
        unset($this->refreshedObjects);
    }

    public function __get(string $name)
    {
        if ($name == "persistentStoreCoordinator") {
            $this->$name = null;
            return $this->$name;
        } elseif ($name == "userInfo" || $name == "byHashAssociationTable") {
            $this->$name = new Dictionary();
            return $this->$name;
        } elseif ($name == "queue") {
            $queue = new OperationQueue();
            $queue->setAssociatedValueForKey($this, "managedObjectContext");
            $this->$name = $queue;
            return $this->$name;
        } elseif ($name == "mergePolicy") {
            $this->$name = MergePolicy::error();
            return $this->$name;
        } elseif ($name == "queryGenerationToken") {
            $this->$name = null;
            return $this->$name;
        } elseif ($name == "registeredObjects") {
            return new Set($this->byHashAssociationTable->values);
        } elseif ($name == "unprocessedChanges" || $name == "unprocessedDeletes" || $name == "unprocessedInserts" || $name == "insertedObjects" || $name == "updatedObjects" || $name == "deletedObjects" || $name == "lockedObjects" || $name == "refreshedObjects") {
            $this->$name = new Set();
            return $this->$name;
        } else {
            return $this->valueForUndefinedKey($name);
        }
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == "persistentStoreCoordinator") {
            $this->$name = $value;
            NotificationCenter::default()->removeObserver($this, PersistentStoreCoordinatorWillRemoveStore);
            NotificationCenter::default()->addObserverForName(PersistentStoreCoordinatorWillRemoveStore, $value, function (Notification $notification): void {
                /** @var Dictionary $userInfo */
                $userInfo = $notification->userInfo;
                /** @var ArrayClass<PersistentStore> $stores */
                $stores = $userInfo[RemovedPersistentStoresKey];
                foreach ($stores as $store) {
                    foreach ($this->byHashAssociationTable as $registeredObject) {
                        if ($store === $registeredObject->objectID->persistentStore) {
                            $this->unregister($registeredObject);
                            $this->insertedObjects->remove($registeredObject);
                            $this->updatedObjects->remove($registeredObject);
                            $this->deletedObjects->remove($registeredObject);
                        }
                    }
                }
            });
        } elseif ($name == "queue" || $name == "mergePolicy" || $name == "queryGenerationToken" || $name == "userInfo" || $name == "byHashAssociationTable" || $name == "unprocessedChanges" || $name == "unprocessedDeletes" || $name == "unprocessedInserts" || $name == "insertedObjects" || $name == "updatedObjects" || $name == "deletedObjects" || $name == "lockedObjects" || $name == "refreshedObjects") {
            $this->$name = $value;
        } else {
            $this->setValueForUndefinedKey($value, $name);
        }
    }

    private function executePersistentStoreRequest(PersistentStoreRequest $request): UnknownRequestTypeResult
    {
        $stores = $request->affectedStores ?? $this->persistentStoreCoordinator?->persistentStores ?? fatal_error("Affected stores cannot be null");
        $this->processingChanges = true;
        $result = new UnknownRequestTypeResult($stores->map(fn(PersistentStore $store): ArrayClass => $store->execute($request, $this)));
        $this->processingChanges = false;
        return $result;
    }

    /**
     * @throws Exception
     */
    private function executeAsynchronousFetchRequest(AsynchronousFetchRequest $asynchronousFetchRequest): AsynchronousFetchResult
    {
        $this->performBlock(function () use ($asynchronousFetchRequest) {
            ($asynchronousFetchRequest->completionBlock)(new AsynchronousFetchResult($asynchronousFetchRequest, $this, $this->fetch($asynchronousFetchRequest->fetchRequest)));
        });
        return new AsynchronousFetchResult($asynchronousFetchRequest, $this, new ArrayClass());
    }

    /**
     * @throws Exception
     */
    private function executeFetchRequest(FetchRequest $request): UnknownRequestTypeResult
    {
        return $request->fetchBatchSize ? match ($request->resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType => new UnknownRequestTypeResult(new BatchFaultingArray($request, $this)),
            default => fatal_error(sprintf("Invalid fetch request: %s->fetchBatchSize cannot be used with %s", FetchRequest::class, human_readable_value($request->resultType))),
        } : $this->executePersistentStoreRequest($request);
    }

    /**
     * @throws Exception
     */
    private function executeSaveChangesRequest(SaveChangesRequest $request): UnknownRequestTypeResult
    {
        $this->processingChanges = true;
        /** @var Set<ManagedObject> $savedObjects */
        $savedObjects = new Set();
        if ($insertedObjects = $request->insertedObjects) {
            $savedObjects->formUnion($insertedObjects);
        }
        if ($updatedObjects = $request->updatedObjects) {
            $savedObjects->formUnion($updatedObjects);
        }
        foreach ($savedObjects as $savedObject) {
            $savedObject->willSave();
        }
        $result = $this->executePersistentStoreRequest($request);
        foreach ($this->deletedObjects as $deletedObject) {
            $this->unregister($deletedObject);
        }
        foreach ($savedObjects as $savedObject) {
            $savedObject->didSave();
        }
        $this->processingChanges = false;
        return $result;
    }

    private function executeBatchInsertRequest(BatchInsertRequest $request): BatchInsertResult
    {
        /** @psalm-suppress InvalidArgument */
        return new BatchInsertResult($this->executePersistentStoreRequest($request)->subresults, $request->resultType);
    }

    private function executeBatchUpdateRequest(BatchUpdateRequest $request): BatchUpdateResult
    {
        /** @psalm-suppress InvalidArgument */
        return new BatchUpdateResult($this->executePersistentStoreRequest($request)->subresults, $request->resultType);
    }

    private function executeBatchDeleteRequest(BatchDeleteRequest $request): BatchDeleteResult
    {
        /** @psalm-suppress InvalidArgument */
        return new BatchDeleteResult($this->executePersistentStoreRequest($request)->subresults, $request->resultType);
    }

    private function executePersistentHistoryChangeRequest(PersistentHistoryChangeRequest $request): PersistentHistoryResult
    {
        /** @psalm-suppress InvalidArgument */
        return new PersistentHistoryResult($this->executePersistentStoreRequest($request)->subresults, $request->resultType);
    }

    /**
     * Passes a request to the persistent store without affecting the contents of the managed object context and returns a persistent store result.
     * @param PersistentStoreRequest $request A persistent store request.
     * @return PersistentStoreResult The result.
     * @throws Exception
     */
    public function execute(PersistentStoreRequest $request): PersistentStoreResult
    {
        if ($request instanceof AsynchronousFetchRequest) {
            return $this->executeAsynchronousFetchRequest($request);
        } elseif ($request instanceof FetchRequest) {
            return $this->executeFetchRequest($request);
        } elseif ($request instanceof SaveChangesRequest) {
            return $this->executeSaveChangesRequest($request);
        } elseif ($request instanceof BatchInsertRequest) {
            return $this->executeBatchInsertRequest($request);
        } elseif ($request instanceof BatchUpdateRequest) {
            return $this->executeBatchUpdateRequest($request);
        } elseif ($request instanceof BatchDeleteRequest) {
            return $this->executeBatchDeleteRequest($request);
        } elseif ($request instanceof PersistentHistoryChangeRequest) {
            return $this->executePersistentHistoryChangeRequest($request);
        } else {
            return $this->executePersistentStoreRequest($request);
        }
    }

    /**
     * Returns an array of objects that meet the criteria specified by a given fetch request.
     *
     * Returned objects are registered with the receiver.
     * The following points are important to consider:
     * If the fetch request has no predicate, then all instances of the specified entity are retrieved, modulo other criteria below.
     * An object that meets the criteria specified by request (it is an instance of the entity specified by the request, and it matches the request's predicate if there is one) and that has been inserted into a context but which is not yet saved to a persistent store, is retrieved if the fetch request is executed on that context.
     * If an object in a context has been modified, a predicate is evaluated against its modified state, not against the current state in the persistent store. Therefore, if an object in a context has been modified such that it meets the fetch request's criteria, the request retrieves it even if changes have not been saved to the store and the values in the store are such that it does not meet the criteria.
     * Conversely, if an object in a context has been modified such that it does not match the fetch request, the fetch request will not retrieve it even if the version in the store does match.
     * If an object has been deleted from the context, the fetch request does not retrieve it even if that deletion has not been saved to a store.
     * Objects that have been realized (populated, faults fired, “read from”, and so on) as well as pending updated, inserted, or deleted, are never changed by a fetch operation without developer intervention.
     * If you fetch some objects, work with them, and then execute a new fetch that includes a superset of those objects, you do not get new instances or update data for the existing objects—you get the existing objects with their current in-memory state.
     * @template T
     * @param FetchRequest<T> $request A fetch request that specifies the search criteria for the fetch.
     * @return ArrayClass<T> An array of objects that meet the criteria specified by request fetched from the receiver and from the persistent stores associated with the receiver's persistent store coordinator.
     * If no objects match the criteria specified by request, returns an empty array.
     * @throws Exception If there is a problem executing the fetch, upon return contains an error that describes the problem.
     */
    public function fetch(FetchRequest $request): ArrayClass
    {
        /** @var UnknownRequestTypeResult $result */
        $result = $this->execute($request);
        $subresults = $result->subresults;
        if ($subresults instanceof BatchFaultingArray) {
            /** @var ArrayClass */
            return $subresults;
        }
        /** @var ArrayClass */
        return new ArrayClass($subresults->joined());
    }

    /**
     * Returns the number of objects a given fetch request would have returned if it had been passed to {@see execute()}.
     * @param FetchRequest<Number> $request A fetch request that specifies the search criteria for the fetch.
     * @return int The number of objects a given fetch request would have returned if it had been passed to {@see fetch()}.
     * @throws Exception If there is a problem executing the fetch, upon return contains an error that describes the problem.
     */
    public function count(FetchRequest $request): int
    {
        $resultType = $request->resultType;
        $request->resultType = FetchRequestResultType::countResultType;
        $result = $this->fetch($request);
        $request->resultType = $resultType;
        return (int)$result->sum();
    }

    /**
     * Returns the object for a specified ID, if the object is registered with the context.
     * @param ManagedObjectID $objectID An object ID.
     * @return ManagedObject|null The object for the specified ID if it is registered with the receiver, otherwise nil.
     */
    public function registeredObject(ManagedObjectID $objectID): ?ManagedObject
    {
        return $this->byHashAssociationTable[(string)$objectID];
    }

    /**
     * Returns an object for a specified ID even if the object needs to be fetched.
     *
     * If the object is not registered in the context, it may be fetched or returned as a fault. This method always returns an object.
     * The data in the persistent store represented by objectID is assumed to exist if it does not, the returned object throws an exception when you access any property (that is, when the fault is fired). The benefit of this behavior is that it allows you to create and use faults, then create the underlying data later or in a separate context.
     * @param ManagedObjectID $objectID An object ID.
     * @return ManagedObject The object for the specified ID.
     */
    public function object(ManagedObjectID $objectID): ManagedObject
    {
        $object = $this->registeredObject($objectID);
        if (!$object instanceof ManagedObject) {
            $object = EntityDescription::insertNewObject($objectID->entityName, $this);
            $this->unregister($object);
            $object->objectID = $objectID;
            $this->register($object);
        }
        return $object;
    }

    /**
     * Returns the object for the specified ID or nil if the object does not exist.
     *
     * If there is a managed object with the given ID already registered in the context, that object is returned directly; otherwise the corresponding object is faulted into the context.
     * This method might perform I/O if the data is uncached.
     * Unlike {@see object()}, this method never returns a fault.
     * @param ManagedObjectID $objectID The object ID for the requested object.
     * @return ManagedObject|null The object specified by objectID. If the object cannot be fetched, or does not exist, or cannot be faulted, it returns nil.
     * @throws Exception If there is a problem in retrieving the object specified by objectID, upon return contains an error that describes the problem.
     */
    public function existingObject(ManagedObjectID $objectID): ?ManagedObject
    {
        $object = $this->registeredObject($objectID);
        if (!$object instanceof ManagedObject) {
            /** @var FetchRequest<ManagedObject> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $objectID->entity;
            $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(SQLEntity::primaryKeyName), Expression::expressionForConstantValue($objectID));
            $object = $this->fetch($fetchRequest)->first;
        }
        return $object;
    }

    /**
     * Refreshes all currently registered objects that are associated with this context.
     * @throws Exception
     */
    public function refreshAllObjects(): void
    {
        foreach ($this->byHashAssociationTable as $registeredObject) {
            $this->refresh($registeredObject, true);
        }
    }

    private function register(ManagedObject $object): void
    {
        $key = (string)$object->objectID;
        if (!$this->byHashAssociationTable[$key]) {
            $this->byHashAssociationTable[$key] = $object;
            $properties = $object::$contextShouldIgnoreUnmodeledPropertyChanges ? $object->persistentProperties : $object->allProperties;
            /** @var PropertyDescription $property */
            foreach ($properties as $property) {
                if (!$property instanceof FetchedPropertyDescription) {
                    $object->addObserver($this, $property->name, KeyValueObservingOptions::new | KeyValueObservingOptions::prior, self::observationContext);
                }
            }
        }
    }

    private function unregister(ManagedObject $object): void
    {
        $key = (string)$object->objectID;
        if ($this->byHashAssociationTable[$key]) {
            $this->byHashAssociationTable->removeValueForKey($key);
            $properties = $object::$contextShouldIgnoreUnmodeledPropertyChanges ? $object->persistentProperties : $object->allProperties;
            foreach ($properties as $property) {
                if (!$property instanceof FetchedPropertyDescription) {
                    $object->removeObserver($this, $property->name);
                }
            }
        }
    }

    /**
     * Creates a log of the inaccessible fault.
     * @param ManagedObject $fault
     * @param ManagedObjectID $oid
     * @param PropertyDescription $property
     * @return bool
     */
    public function shouldHandleInaccessibleFault(ManagedObject $fault, ManagedObjectID $oid, PropertyDescription $property): bool
    {
        if ($this->shouldDeleteInaccessibleFaults) {
            $this->delete($fault);
            return true;
        }
        fatal_error(sprintf("Inaccessible fault <%s %s:objectID=%s property=%s>", $fault::class, $fault->hash(), $oid->description(), $property->description()));
    }

    /**
     * Registers an object to be inserted in the context's persistent store the next time changes are saved.
     * @param ManagedObject $object A managed object.
     */
    public function insert(ManagedObject $object): void
    {
        $object->awakeFromInsert();
        if (!$this->processingChanges) {
            $this->hasChanges = true;
            $this->insertedObjects->append($object);
        }
        $this->register($object);
    }

    /**
     * Specifies an object that should be removed from its persistent store when changes are committed.
     * @param ManagedObject $object A managed object.
     */
    public function delete(ManagedObject $object): void
    {
        $this->hasChanges = true;
        $this->deletedObjects->append($object);
        $this->insertedObjects->remove($object);
        $this->updatedObjects->remove($object);
        $object->prepareForDeletion();
        $this->refault($object);
    }

    /**
     * Specifies the store in which a newly inserted object will be saved.
     *
     * You can obtain a store from the persistent store coordinator, using for example {@see PersistentStoreCoordinator::persistentStore()}.
     * It is only necessary to use this method if the receiver's persistent store coordinator manages multiple writable stores that have object's entity in their configuration. Maintaining configurations in the managed object model can eliminate the need for invoking this method directly in many situations. If the receiver's persistent store coordinator manages only a single writable store, or if only one store has object's entity in its model, object will automatically be assigned to that store.
     * @param ManagedObject $object A managed object.
     * @param PersistentStore $store A persistent store.
     */
    public function assign(ManagedObject $object, PersistentStore $store): void
    {
        $store->obtainPermanentIDs(new ArrayClass([$object]));
    }

    /**
     * Converts to permanent IDs the object IDs of the objects in a given array.
     *
     * This method converts the object ID of each managed object in objects to a permanent ID.
     * Although the object will have a permanent ID, it will still respond positively to isInserted until it is saved.
     * Any object that already has a permanent ID is ignored.
     * Any object not already assigned to a store is assigned based on the same rules Core Data uses for assignment during a save operation (first writable store supporting the entity, and appropriate for the instance and its related items).
     * @param ArrayClass<ManagedObject> $objects An array of managed objects.
     * @return bool true if permanent IDs are obtained for all the objects in objects, otherwise false.
     */
    public function obtainPermanentIDs(ArrayClass $objects): bool
    {
        $results = $objects->map(fn(ManagedObject $object): bool => $this->obtainPermanentID($object));
        NotificationCenter::default()->postNotificationName(self::didSaveObjectIDsNotification, $this);
        return !$results->containsElement(false);
    }

    private function obtainPermanentID(ManagedObject $object): bool
    {
        if ($object->objectID->isTemporaryID && (($persistentStore = $this->persistentStoreCoordinator?->persistentStoreForObject($object)))) {
            $object->objectID = $persistentStore->objectID($object->entity, $persistentStore->newReferenceObject($object));
            $this->register($object);
            return true;
        }
        return false;
    }

    /**
     * Marks an object for conflict detection.
     *
     * If on the next invocation of {@see save()} object has been modified in its persistent store, the save fails. This allows optimistic locking for unchanged objects. Conflict detection is always performed on changed or deleted objects.
     * @param ManagedObject $object A managed object.
     * @throws Exception
     */
    public function detectConflicts(ManagedObject $object): void
    {
        if ($object->isDeleted) {
            /** @psalm-suppress InvalidArgument */
            $this->mergePolicy->resolveConflicts($object->entity->relationshipsByName->compactMap(function (RelationshipDescription $relationship) use ($object): ?MergeConflict {
                if ($relationship->inverseRelationship->deleteRule == DeleteRule::denyDeleteRule) {
                    /** @var FetchRequest<ManagedObject> $fetchRequest */
                    $fetchRequest = new FetchRequest();
                    $fetchRequest->entity = $object->entity;
                    $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(SQLEntity::primaryKeyName), Expression::expressionForConstantValue($object->objectID));
                    if ($store = $object->objectID->persistentStore) {
                        $fetchRequest->affectedStores = new ArrayClass([$store]);
                    }
                    $persistedObjects = $this->fetch($fetchRequest);
                    if ($persistedObject = $persistedObjects->first) {
                        $persistentPropertyKeys = $object->persistentProperties->map(fn(PropertyDescription $property): string => $property->name);
                        return new MergeConflict($object, 0, 1, $object->dictionaryWithValues($persistentPropertyKeys), $persistedObject->dictionaryWithValues($persistentPropertyKeys));
                    }
                }
                return null;
            }));
        }
    }

    /**
     * @throws Exception
     */
    private function doPreSaveConstraintChecksForObject(ManagedObject $object): void
    {
        $committedValues = $object->committedValuesForKeys(null);
        /** @psalm-suppress InvalidArgument */
        $this->mergePolicy->resolveConstraintConflicts($committedValues->compactMap(function (mixed $value, string $key) use ($object): ?ConstraintConflict {
            if ($object->entity->indexes->contains(fn(FetchIndexDescription $index): bool => $index->name === $key && $index->isUnique())) {
                $attributeKeys = $object->entity->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$attribute instanceof DerivedAttributeDescription)->keys;
                /** @var FetchRequest<ManagedObject> $fetchRequest */
                $fetchRequest = new FetchRequest();
                $fetchRequest->entity = $object->entity;
                $fetchRequest->predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([new ComparisonPredicate(Expression::expressionForKeyPath($key), Expression::expressionForConstantValue($value)), new ComparisonPredicate(Expression::expressionForKeyPath(SQLEntity::primaryKeyName), Expression::expressionForConstantValue($object->objectID), PredicateOperatorType::notEqualTo)]));
                $fetchRequest->propertiesToFetch = $attributeKeys;
                if ($store = $object->objectID->persistentStore) {
                    $fetchRequest->affectedStores = new ArrayClass([$store]);
                }
                $databaseObjects = $this->fetch($fetchRequest);
                if ($databaseObject = $databaseObjects->first) {
                    $databaseSnapshot = $databaseObject->dictionaryWithValues($attributeKeys);
                    return new ConstraintConflict(new ArrayClass([$key]), $databaseObject, $databaseSnapshot, new ArrayClass([$object, $databaseObject]), new ArrayClass([$object->changedValues(), $databaseSnapshot]));
                }
            }
            return null;
        }));
    }

    /**
     * Updates the persistent properties of a managed object to use the latest values from the persistent store.
     * @param ManagedObject $object A managed object.
     * @param bool $mergeChanges A Boolean value.
     * If flag is false, then object is turned into a fault and any pending changes are lost.
     * The object remains a fault until it is accessed again, at which time its property values will be reloaded from the store or last cached state.
     * If flag is true, then object is turned into a fault and object's property values are reloaded from the values from the store or the last cached state then any changes that were made (in the local context) are re-applied over those (now newly updated) values.
     * (If flag is true the merge of the values into object will always succeed in this case there is therefore no such thing as a “merge conflict” or a merge that is not possible.)
     */
    public function refresh(ManagedObject $object, bool $mergeChanges = false): void
    {
        $this->refreshedObjects->append($object);
        $this->refault($object, $mergeChanges);
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    private function refault(ManagedObject $object, bool $mergeChanges = false): void
    {
        if ($mergeChanges) {
            $this->save();
        }
        $faultHandler = $object->faultHandler;
        $faultHandler->turnObjectIntoFault($object, $this);
        if ($mergeChanges) {
            $faultHandler->fulfillFault($object, $this);
        }
    }

    private function resetAllChanges(): void
    {
        $this->unprocessedChanges->removeAll();
        $this->unprocessedInserts->removeAll();
        $this->unprocessedDeletes->removeAll();
    }

    /**
     * @param Set<ManagedObject> $insertions
     * @param RelationshipDescription $relationship
     * @param ManagedObject $object
     * @throws Exception
     */
    private function processPendingInsertions(Set $insertions, RelationshipDescription $relationship, ManagedObject $object): void
    {
        $inverseRelationship = $relationship->inverseRelationship;
        if ($relationship->isToMany) {
            if ($inverseRelationship->isToMany) {
                foreach ($insertions as $insertion) {
                    $set = $insertion->mutableSetValueForKey($inverseRelationship->name);
                    $set->append($object->objectID);
                }
                $store = $object->objectID->persistentStore;
                if ($store instanceof SQLCore) {
                    /** @var SQLEntity $entity */
                    $entity = $store->model->entitiesByName[$object->entity->name];
                    if ($manyToMany = $entity->manyToManyRelationships->first(fn(SQLManyToMany $manyToMany): bool => $manyToMany->relationshipDescription->isEqual($relationship))) {
                        (new SQLCorrelationTableUpdateTracker($manyToMany))->track($object->objectID, $insertions);
                    }
                }
            } else {
                $insertions->setValueForKey($object, $inverseRelationship->name);
            }
        } else {
            $object->setValueForKey($insertions->first, $relationship->name);
            $this->updatedObjects->append($object);
        }
        $this->insertedObjects->formUnion($insertions);
    }

    /**
     * @param Set<ManagedObject> $deletions
     * @param RelationshipDescription $relationship
     * @param ManagedObject $object
     * @throws Exception
     */
    private function processPendingDeletions(Set $deletions, RelationshipDescription $relationship, ManagedObject $object): void
    {
        $inverseRelationship = $relationship->inverseRelationship;
        $deleteRule = $relationship->deleteRule;
        if ($deleteRule == DeleteRule::nullifyDeleteRule) {
            if ($relationship->isToMany) {
                if ($inverseRelationship->isToMany) {
                    foreach ($deletions as $deletion) {
                        $set = $deletion->mutableSetValueForKey($inverseRelationship->name);
                        $set->remove($object->objectID);
                    }
                    $store = $object->objectID->persistentStore;
                    if ($store instanceof SQLCore) {
                        /** @var SQLEntity $entity */
                        $entity = $store->model->entitiesByName[$object->entity->name];
                        if ($manyToMany = $entity->manyToManyRelationships->first(fn(SQLManyToMany $manyToMany): bool => $manyToMany->relationshipDescription->isEqual($relationship))) {
                            (new SQLCorrelationTableUpdateTracker($manyToMany))->track($object->objectID, deletes: $deletions);
                        }
                    }
                } else {
                    $object->setPrimitiveValueForKey(null, $relationship->name);
                    $this->deletedObjects->remove($object);
                    $this->insertedObjects->remove($object);
                    $this->updatedObjects->append($object);
                    foreach ($deletions as $deletion) {
                        $deletion->setPrimitiveValueForKey(null, $inverseRelationship->name);
                        $this->deletedObjects->remove($deletion);
                        $this->insertedObjects->remove($deletion);
                        $this->updatedObjects->append($deletion);
                    }
                }
            } else {
                $object->setPrimitiveValueForKey(null, $relationship->name);
                $this->deletedObjects->remove($object);
                $this->insertedObjects->remove($object);
                $this->updatedObjects->append($object);
            }
        } elseif ($deleteRule == DeleteRule::cascadeDeleteRule) {
            foreach ($deletions as $deletion) {
                $this->delete($deletion);
            }
        }
    }

    /**
     * @param Set<ManagedObject> $updates
     * @param RelationshipDescription $relationship
     * @param ManagedObject $object
     */
    private function processPendingUpdates(/** @noinspection PhpUnusedParameterInspection */ Set $updates, RelationshipDescription $relationship, ManagedObject $object): void
    {
        $this->updatedObjects->formUnion($updates->filter(fn(ManagedObject $managedObject): bool => !$managedObject->isDeleted));
    }

    /**
     * Forces the context to process changes to the object graph.
     * @throws Exception
     */
    public function processPendingChanges(): void
    {
        if (!$this->processingChanges) {
            $this->processingChanges = true;
            foreach ($this->unprocessedInserts as $unprocessedInsert) {
                $object = $this->object($unprocessedInsert->objectID);
                foreach ($object->persistentProperties as $persistentProperty) {
                    if (($value = $unprocessedInsert->valueForProperty($persistentProperty)) && $persistentProperty instanceof RelationshipDescription) {
                        $this->processPendingInsertions($value, $persistentProperty, $object);
                    }
                }
            }
            foreach ($this->unprocessedDeletes as $unprocessedDelete) {
                $object = $this->object($unprocessedDelete->objectID);
                foreach ($object->persistentProperties as $persistentProperty) {
                    if (($value = $unprocessedDelete->valueForProperty($persistentProperty)) && $persistentProperty instanceof RelationshipDescription) {
                        $this->processPendingDeletions($value, $persistentProperty, $object);
                    }
                }
            }
            foreach ($this->unprocessedChanges as $unprocessedChange) {
                $attributesChanged = false;
                $object = $this->object($unprocessedChange->objectID);
                foreach ($object->persistentProperties as $persistentProperty) {
                    if ($value = $unprocessedChange->valueForProperty($persistentProperty)) {
                        if ($persistentProperty instanceof RelationshipDescription) {
                            $this->processPendingUpdates($value, $persistentProperty, $object);
                        } else {
                            $attributesChanged = true;
                        }
                    }
                }
                if ($attributesChanged) {
                    $this->updatedObjects->append($object);
                }
            }
            $this->resetAllChanges();
            NotificationCenter::default()->postNotificationName(self::didChangeObjectsNotification, $this, new Dictionary([InsertedObjectsKey => $this->insertedObjects, UpdatedObjectsKey => $this->updatedObjects, DeletedObjectsKey => $this->deletedObjects]));
            $this->processingChanges = false;
        }
    }

    public function observeValue(string $keyPath, mixed $object, KeyValueObservedChange $change, mixed $context = null): void
    {
        if ($context === self::observationContext) {
            if ($this->processingChanges || !($value = $change->newValue) || !$object instanceof ManagedObject || $object->isSuppressingKVO || $object->isSuppressingChangeNotifications) {
                return;
            }
            if (!($property = $object->entity->propertiesByName[$keyPath])) {
                return;
            }
            if ($property instanceof RelationshipDescription) {
                assert($value instanceof Set || $value instanceof ManagedObject || $value instanceof ManagedObjectID, sprintf("invalid argument: %s(%s) expecting \"%s|%s|%s\", \"%s\" given", $object->entity->name, $keyPath, Set::class, ManagedObject::class, ManagedObjectID::class, typeof($value)));
                if (!$value instanceof Set) {
                    if ($value instanceof ManagedObjectID) {
                        $value = $this->object($value);
                    }
                    $value = new Set([$value]);
                }
                if ($change->kind !== KeyValueChange::removal && $value->isEmpty) {
                    return;
                }
                foreach ($value as $managedObject) {
                    assert($managedObject instanceof ManagedObject, sprintf("invalid argument: expecting \"%s\", \"%s\" given", ManagedObject::class, typeof($managedObject)));
                    $this->obtainPermanentID($managedObject);
                }
            }
            $this->obtainPermanentID($object);
            $this->hasChanges = true;
            $node = new IncrementalStoreNode($object->objectID, new Dictionary([$property->name => $value]));
            switch ($change->kind) {
                case KeyValueChange::insertion:
                    if ($member = $this->unprocessedInserts->member($node)) {
                        $node->updateWithValues($member->values);
                    }
                    $this->unprocessedInserts->update($node);
                    break;
                case KeyValueChange::removal:
                    if ($member = $this->unprocessedDeletes->member($node)) {
                        $node->updateWithValues($member->values);
                    }
                    $this->unprocessedDeletes->update($node);
                    break;
                case KeyValueChange::setting:
                case KeyValueChange::replacement:
                    if ($member = $this->unprocessedChanges->member($node)) {
                        $node->updateWithValues($member->values);
                    }
                    $this->unprocessedChanges->update($node);
                    break;
            }
        } else {
            parent::observeValue($keyPath, $object, $change, $context);
        }
    }

    /**
     * Handles changes from other processes or from a serialized state.
     *
     * This method more efficiently merges changes into multiple contexts as well as nested contexts. The dictionary keys should be one or more from an {@see ManagedObjectContextObjectsDidChange}: {@see InsertedObjectsKey}, {@see UpdatedObjectsKey}, {@see DeletedObjectsKey}. The values should be a {@see ArrayClass} of either {@see ManagedObjectID} or {@see URL} objects conforming to valid results from {@see ManagedObjectID::uriRepresentation()}.
     * @param Dictionary<ArrayClass<ManagedObjectID|ManagedObject|URL>> $changeNotificationData
     * @param ArrayClass<ManagedObjectContext> $contexts
     */
    public static function mergeChangesFromRemoteContextSave(Dictionary $changeNotificationData, ArrayClass $contexts): void
    {
        if ($insertedObjects = $changeNotificationData[InsertedObjectsKey]) {
            foreach ($insertedObjects as $insertedObject) {
                foreach ($contexts as $context) {
                    if ($insertedObject instanceof ManagedObject && $insertedObject->managedObjectContext !== $context) {
                        $context->insert($insertedObject);
                    }
                }
            }
        }
    }

    /**
     * Merges the changes specified in a given notification.
     *
     * This method refreshes any objects which have been updated in the other context, faults in any newly-inserted objects, and invokes {@see delete()} on those which have been deleted.
     * You can pass a {@see ManagedObjectContextDidSave} posted by a managed object context on another thread, however you must not use the managed objects in the user info dictionary directly.
     * @param Notification $notification A notification posted by another context.
     */
    public function mergeChangesFromContextDidSaveNotification(Notification $notification): void
    {
    }

    /**
     * Sets the query generation this context should use.
     * @param QueryGenerationToken $generation
     */
    public function setQueryGenerationFrom(QueryGenerationToken $generation): void
    {
        $this->queryGenerationToken = $generation;
    }

    /**
     * @throws Exception
     */
    private function newSaveRequestForCurrentState(): ?SaveChangesRequest
    {
        $this->obtainPermanentIDs(new ArrayClass($this->insertedObjects));
        $insertedObjects = clone $this->insertedObjects;
        foreach ($insertedObjects as $insertedObject) {
            if ($insertedObject->isInserted) {
                $this->insertedObjects->remove($insertedObject);
                if ($insertedObject->isUpdated) {
                    $this->updatedObjects->append($insertedObject);
                }
                continue;
            }
            $insertedObject->validateForInsert();
            $this->doPreSaveConstraintChecksForObject($insertedObject);
        }
        $updatedObjects = clone $this->updatedObjects;
        foreach ($updatedObjects as $updatedObject) {
            if (!$updatedObject->isUpdated) {
                $this->updatedObjects->remove($updatedObject);
                continue;
            }
            $updatedObject->validateForUpdate();
            $this->detectConflicts($updatedObject);
            $this->doPreSaveConstraintChecksForObject($updatedObject);
        }
        foreach ($this->deletedObjects as $deletedObject) {
            $deletedObject->validateForDelete();
            $this->detectConflicts($deletedObject);
        }
        if ($this->insertedObjects->isEmpty && $this->updatedObjects->isEmpty && $this->deletedObjects->isEmpty) {
            return null;
        }
        return new SaveChangesRequest($this->insertedObjects->isEmpty ? null : $this->insertedObjects, $this->updatedObjects->isEmpty ? null : $this->updatedObjects, $this->deletedObjects->isEmpty ? null : $this->deletedObjects);
    }

    /**
     * Attempts to commit unsaved changes to registered objects to the context's parent store.
     *
     * If there were multiple errors (for example several edited objects had validation failures) the description of Error returned indicates that there were multiple errors, and its userInfo dictionary contains the key DetailedErrors. The value associated with the DetailedErrors key is an array that contains the individual Error objects.
     * If a context's parent store is a persistent store coordinator, then changes are committed to the external store. If a context's parent store is another managed object context, then {@see save()} only updates managed objects in that parent store. To commit changes to the external store, you must save changes in the chain of contexts up to and including the context whose parent is the persistent store coordinator.
     * Always verify that the context has uncommitted changes (using the {@see hasChanges} property) before invoking the save: method. Otherwise, Core Data may perform unnecessary work.
     * @return bool true if the save succeeds, otherwise false.
     * @throws Exception
     */
    public function save(): bool
    {
        if ($this->savingInProgress) {
            return true;
        }
        $this->savingInProgress = true;
        $this->processPendingChanges();
        if ($changesRequest = $this->newSaveRequestForCurrentState()) {
            NotificationCenter::default()->postNotificationName(self::willSaveObjectsNotification, $this);
            $this->execute($changesRequest);
            NotificationCenter::default()->postNotificationName(self::didSaveObjectsNotification, $this, new Dictionary([InsertedObjectsKey => $changesRequest->insertedObjects, UpdatedObjectsKey => $changesRequest->updatedObjects, DeletedObjectsKey => $changesRequest->deletedObjects]));
        }
        $this->insertedObjects->removeAll();
        $this->updatedObjects->removeAll();
        $this->deletedObjects->removeAll();
        $this->hasChanges = false;
        $this->savingInProgress = false;
        return true;
    }

    /**
     * Sends an undo message to the context’s undo manager, asking it to reverse the latest uncommitted changes applied to objects in the object graph.
     */
    public function undo(): void
    {
        $this->undoManager?->undo();
    }

    /**
     * Sends a redo message to the context’s undo manager, asking it to reverse the latest undo operation applied to objects in the object graph.
     */
    public function redo(): void
    {
        $this->undoManager?->redo();
    }

    /**
     * Returns the context to its base state.
     *
     * All the receiver's managed objects are “forgotten.” If you use this method, you should ensure that you also discard references to any managed objects fetched using the receiver, since they will be invalid afterward.
     */
    public function reset(): void
    {
        foreach ($this->byHashAssociationTable as $registeredObject) {
            $this->unregister($registeredObject);
        }
        $this->insertedObjects->removeAll();
        $this->updatedObjects->removeAll();
        $this->deletedObjects->removeAll();
        $this->byHashAssociationTable->removeAll();
        $this->hasChanges = false;
    }

    /**
     * Removes everything from the undo stack, discards all insertions and deletions, and restores updated objects to their last committed values.
     *
     * This method does not refetch data from the persistent store or stores.
     */
    public function rollback(): void
    {
        /** @var Set<ManagedObject> $updatedObjects */
        $updatedObjects = new Set($this->updatedObjects);
        foreach ($updatedObjects as $updatedObject) {
            $this->refault($updatedObject);
            $updatedObject->awakeFromSnapshotEvents(SnapshotEventType::rollback | SnapshotEventType::refresh);
        }
        $this->insertedObjects->removeAll();
        $this->updatedObjects->removeAll();
        $this->deletedObjects->removeAll();
        $this->hasChanges = false;
    }

    /**
     * Asynchronously performs a given block on the context's queue.
     * @param Closure(): void $block The block to perform.
     */
    public function performBlock(Closure $block): void
    {
        $this->queue->addOperationWithBlock($block);
    }

    /**
     * Synchronously performs a given block on the context's queue.
     * @param Closure(): void $block The block to perform.
     */
    public function performBlockAndWait(Closure $block): void
    {
        $this->queue->addOperationWithBlock($block);
    }
}
