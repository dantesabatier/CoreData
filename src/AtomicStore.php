<?php

namespace Sabatier\CoreData;

use Exception;
use InvalidArgumentException;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\request_concrete_implementation;
use const Sabatier\Foundation\NotFound;

/**
 * An abstract superclass that you subclass to create a Core Data atomic store.
 *
 * This class provides default implementations of some utility methods.
 * You use a custom atomic store if you have a custom file format that you want to integrate with a Core Data application.
 * The atomic stores are all intended to handle data sets that can be expressed in memory.
 * The atomic store API favors simplicity over performance.
 */
abstract class AtomicStore extends PersistentStore
{
    /** @var Dictionary<AtomicStoreCacheNode> */
    private Dictionary $nodeCache;
    /** @var Dictionary<Dictionary<ManagedObjectID>> */
    private Dictionary $cacheEntities;
    private int $nextReference = NotFound;

    public function __construct(PersistentStoreCoordinator $coordinator, string $configurationName, URL $url, ?Dictionary $options = null)
    {
        parent::__construct($coordinator, $configurationName, $url, $options);
        $this->cacheEntities = new Dictionary();
        $this->nodeCache = new Dictionary();
    }

    private function addObject(ManagedObject $object): void
    {
        $key = (string)$object->objectID;
        if (!$this->nodeCache[$key]) {
            $cacheNode = $this->newCacheNode($object);
            $this->nodeCache[$key] = $cacheNode;
            $this->updateObject($object);
            $object->awakeFromFetch();
        }
    }

    private function updateObject(ManagedObject $object): void
    {
        $cacheNode = $this->cacheNode($object->objectID) ?? throw new InvalidArgumentException("Invalid argument: object \"$object\" does not exists ");
        foreach ($object->entity as $property) {
            if (!$property instanceof DerivedAttributeDescription && !$property instanceof FetchedPropertyDescription) {
                $key = $property->name;
                $value = $cacheNode->valueForKey($key);
                if ($value !== null) {
                    $object->setValueForKey($value, $key);
                }
            }
        }
        $this->updateCacheNode($cacheNode, $object);
    }

    private function removeObject(ManagedObject $object): void
    {
        $this->nodeCache->removeValueForKey((string)$object->objectID);
    }

    /**
     * @throws Exception
     */
    private function storeNextReferenceInMetadata(): void
    {
        $metadata = $this->metadata;
        if ($this->nextReference === NotFound || $this->nextReference === (int)$metadata["StoreNextReference"]) {
            return;
        }
        $metadata["StoreNextReference"] = $this->nextReference;
        static::setMetadata($metadata, $this->url);
    }

    /**
     * @param Set<ManagedObject> $objects
     * @return Dictionary<Set<ManagedObject>>
     */
    private function groupedObjects(Set $objects): Dictionary
    {
        /** @var Dictionary<Set<ManagedObject>> $dictionary */
        $dictionary = new Dictionary();
        foreach ($objects as $object) {
            $entity = $object->entity;
            $key = $entity->name;
            /** @var Set<ManagedObject> $value */
            $value = $dictionary[$key] ?? new Set();
            $value->append($object);
            $dictionary[$key] = $value;
        }
        return $dictionary;
    }

    private function executeFetchRequest(FetchRequest $request, ManagedObjectContext $context): ArrayClass
    {
        $resultType = $request->resultType;
        /** @var ArrayClass<PropertyDescription|string> $propertiesToGroupBy */
        $propertiesToGroupBy = $request->propertiesToGroupBy ?? new ArrayClass();
        if (!$propertiesToGroupBy->isEmpty() && $resultType !== FetchRequestResultType::dictionaryResultType) {
            throw new InvalidArgumentException(sprintf("Invalid fetch request: GROUP BY requires %s, %s given", human_readable_value(FetchRequestResultType::dictionaryResultType), human_readable_value($request->resultType)));
        }
        /** @var ArrayClass<ManagedObject> $objects */
        $objects = new ArrayClass();
        /** @var AtomicStoreCacheNode $cacheNode */
        foreach ($this->nodeCache as $cacheNode) {
            $object = $context->object($cacheNode->objectID);
            $this->updateObject($object);
            if ($request->includesSubentities) {
                if ($cacheNode->objectID->entity->isKindOf($request->entity)) {
                    $objects->append($object);
                }
            } elseif ($cacheNode->objectID->entity->isEqual($request->entity)) {
                $objects->append($object);
            }
        }
        if ($resultType === FetchRequestResultType::managedObjectResultType) {
            if ($predicate = $request->predicate) {
                $objects = $objects->filtered($predicate);
            }
            $objects = $objects->map(fn(ManagedObject $object): ManagedObject => $object->serialized($request->serialization));
            if ($descriptors = $request->sortDescriptors) {
                $objects = $objects->sorted($descriptors);
            }
        } elseif ($resultType === FetchRequestResultType::managedObjectIDResultType) {
            if ($predicate = $request->predicate) {
                $objects = $objects->filtered($predicate);
            }
            if ($descriptors = $request->sortDescriptors) {
                $objects = $objects->sorted($descriptors);
            }
            $objects = $objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID);
        } elseif ($resultType === FetchRequestResultType::dictionaryResultType) {
            if (!$propertiesToGroupBy->isEmpty()) {
                /** @var Dictionary<ArrayClass<ManagedObject>> $dictionary */
                $dictionary = new Dictionary();
                foreach ($objects as $object) {
                    foreach ($propertiesToGroupBy as $property) {
                        $key = $property instanceof PropertyDescription ? $property->name : $property;
                        $value = $dictionary[$key];
                        if ($value instanceof ArrayClass) {
                            $value[] = $object;
                        } else {
                            $dictionary[$key] = new ArrayClass([$object]);
                        }
                    }
                }
                /** @var ArrayClass<ManagedObject> $objects */
                $objects = new ArrayClass($dictionary->joined());
                if ($havingPredicate = $request->havingPredicate) {
                    $objects = $objects->filtered($havingPredicate);
                }
            }
            if ($predicate = $request->predicate) {
                $objects = $objects->filtered($predicate);
            }
            $objects = $objects->map(fn(ManagedObject $object): Dictionary => $object->jsonSerialize());
            if (($propertiesToFetch = $request->propertiesToFetch) && !$propertiesToFetch->isEmpty()) {
                /** @var ArrayClass<ExpressionDescription> $expressionDescriptions */
                $expressionDescriptions = $propertiesToFetch->filter(fn(PropertyDescription|string $property): bool => $property instanceof ExpressionDescription);
                foreach ($expressionDescriptions as $expressionDescription) {
                    if ($expression = $expressionDescription->expression) {
                        foreach ($objects as $object) {
                            $object[$expressionDescription->name] = ManagedObject::coercedValue($expression->expressionValue(new ArrayClass([$object])), $expressionDescription->expressionResultType);
                        }
                    }
                }
                $keys = $propertiesToFetch->map(fn(PropertyDescription|string $property): string => $property instanceof PropertyDescription ? $property->name : $property);
                $keys->insertAt("objectID", 0);
                $keys->insertAt("entityName", 1);
                foreach ($objects as $object) {
                    foreach ($object->keys as $key) {
                        if (!$keys->containsElement($key)) {
                            $object->removeValueForKey($key);
                        }
                    }
                }
            }
            if ($descriptors = $request->sortDescriptors) {
                $objects = $objects->sorted($descriptors);
            }
        } else {
            $objects = new ArrayClass([new Number($objects->count())]);
        }
        return $objects;
    }

    private function executeRefreshRequest(RefreshRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->groupedObjects($request->refreshObjects)->flatMap(function (Set $objects, string $key) use ($context): Set|ArrayClass {
            if (!$objects->isEmpty() && ($entity = $this->persistentStoreCoordinator->managedObjectModel->entitiesByName[$key])) {
                $fetchRequest = new FetchRequest();
                $fetchRequest->entity = $entity;
                $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath("objectID"), Expression::expressionForConstantValue($objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID)), PredicateOperatorType::in);
                $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
                return $this->executeFetchRequest($fetchRequest, $context);
            }
            return $objects;
        });
    }

    /**
     * @throws Exception
     */
    private function executeSaveChangesRequest(/** @noinspection PhpUnusedParameterInspection */ SaveChangesRequest $request, ManagedObjectContext $context): ArrayClass
    {
        if ($this->isReadOnly) {
            throw new InternalInconsistencyException("Cannot modify a read only persistent store");
        }
        if ($deletedObjects = $request->deletedObjects) {
            /** @var Set<AtomicStoreCacheNode> $deletedNodes */
            $deletedNodes = new Set();
            foreach ($deletedObjects as $deletedObject) {
                $deletedNodes->append($this->cacheNode($deletedObject->objectID) ?? throw new InternalInconsistencyException("Unable to delete an uncached object $deletedObject"));
                $this->removeObject($deletedObject);
            }
            $this->willRemoveCacheNodes($deletedNodes);
        }
        if ($insertedObjects = $request->insertedObjects) {
            foreach ($insertedObjects as $insertedObject) {
                $this->addObject($insertedObject);
            }
        }
        if ($updatedObjects = $request->updatedObjects) {
            foreach ($updatedObjects as $updatedObject) {
                $this->updateCacheNode($this->cacheNode($updatedObject->objectID) ?? throw new InvalidArgumentException("Unable to update an uncached object $updatedObject"), $updatedObject);
            }
        }
        $this->save();
        $this->storeNextReferenceInMetadata();
        return new ArrayClass();
    }

    public function execute(PersistentStoreRequest $request, ManagedObjectContext $context): ArrayClass
    {
        if ($request instanceof FetchRequest) {
            return $this->executeFetchRequest($request, $context);
        } elseif ($request instanceof RefreshRequest) {
            return $this->executeRefreshRequest($request, $context);
        } elseif ($request instanceof SaveChangesRequest) {
            return $this->executeSaveChangesRequest($request, $context);
        } else {
            return new ArrayClass();
        }
    }

    public function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): ?AtomicStoreCacheNode
    {
        if (!($object = $context->existingObject($objectID))) {
            return null;
        }
        return $this->newCacheNode($object);
    }

    public function newValueForRelationship(RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): mixed
    {
        $entity = $objectID->entity;
        $destinationEntity = $relationship->destinationEntity;
        $inverseRelationship = $relationship->inverseRelationship;
        if (!$entity->isAbstract && !$destinationEntity->isAbstract && !$objectID->isTemporaryID) {
            if ($relationship->isToMany) {
                if ($inverseRelationship->isToMany) {
                    return $this->nodeCache->filter(fn(AtomicStoreCacheNode $node): bool => $node->objectID->entity->isKindOf($destinationEntity) && $node->valueForKey($inverseRelationship->name)?->containsElement($objectID))->map(fn(AtomicStoreCacheNode $node): ManagedObjectID => $node->objectID);
                } else {
                    return $this->nodeCache->filter(fn(AtomicStoreCacheNode $node): bool => $node->objectID->entity->isKindOf($destinationEntity) && $node->valueForKey($inverseRelationship->name)?->isEqual($objectID))->map(fn(AtomicStoreCacheNode $node): ManagedObjectID => $node->objectID);
                }
            } else {
                return $this->nodeCache->filter(fn(AtomicStoreCacheNode $node): bool => $node->objectID->entity->isKindOf($destinationEntity) && !$inverseRelationship->isToMany && $node->valueForKey($inverseRelationship->name)?->isEqual($objectID))->first()?->objectID;
            }
        }
        if ($relationship->isToMany) {
            return new ArrayClass();
        }
        return null;
    }

    public function obtainPermanentIDs(ArrayClass $objects): ArrayClass
    {
        return $objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID->isTemporaryID ? $this->objectID($object->entity, $this->newReferenceObject($object)) : $object->objectID);
    }

    public function referenceObject(ManagedObjectID $objectID): int|string
    {
        /** @var ManagedObjectID $managedObjectID */
        $managedObjectID = $this->cacheEntities[$objectID->entity->name][(string)$objectID] ?? throw new InvalidArgumentException("Object id wasn't created by this store.");
        return $managedObjectID->referenceObject;
    }

    public function newReferenceObject(ManagedObject $managedObject): int|string
    {
        if ($this->nextReference === NotFound) {
            $this->nextReference = (int)$this->metadata["StoreNextReference"];
        }
        $this->nextReference += 1;
        return $this->nextReference;
    }

    /**
     * Loads the cache nodes for the receiver.
     *
     * You override this method to load the data from the URL specified in {@see __construct()} and create cache nodes for the represented objects.
     * You must respect the configuration specified for the store, as well as the options.
     * Any subclass of AtomicStore must be able to handle being initialized with a URL pointing to a zero-length file.
     * This serves as an indicator that a new store is to be constructed at the specified location and allows you to securely create reservation files in known locations which can then be passed to Core Data to construct stores.
     * You may choose to create zero-length reservation files during {@see __construct()} or {@see load()}.
     * If you do so, you must remove the reservation file if the store is removed from the coordinator before it is saved.
     * @return bool true if the cache nodes were loaded correctly, otherwise false.
     * @throws Exception
     */
    public function load(): bool
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
    public function objectID(EntityDescription $entity, int|string $referenceObject): ManagedObjectID
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
     * Registers a set of cache nodes with the receiver.
     *
     * You should invoke this method in a subclass during the call to load() to register the loaded information with the store.
     * @param Set<AtomicStoreCacheNode> $cacheNodes A set of cache nodes.
     */
    public function addCacheNodes(Set $cacheNodes): void
    {
        foreach ($cacheNodes as $cacheNode) {
            $this->nodeCache[(string)$cacheNode->objectID] = $cacheNode;
        }
    }

    /**
     * Returns a new cache node for a given managed object.
     *
     * This method is invoked by the framework during a save operation, once for each newly-inserted managed object.
     * It should pull information from the managed object and return a cache node containing the information (the node will be registered by the framework).
     * You must override this method.
     * @param ManagedObject $object A managed object.
     * @return AtomicStoreCacheNode A new cache node for managedObject.
     */
    public function newCacheNode(ManagedObject $object): AtomicStoreCacheNode
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Updates the given cache node using the values in a given managed object.
     *
     * This method is invoked by the framework after a save operation on a managed object context, once for each updated ManagedObject instance.
     * You override this method in a subclass to take the information from managedObject and update node.
     * You must override this method.
     * @param AtomicStoreCacheNode $node The cache node to update.
     * @param ManagedObject $object The managed object with which to update node.
     */
    public function updateCacheNode(AtomicStoreCacheNode $node, ManagedObject $object): void
    {
    }

    /**
     * Method invoked before the store removes the given collection of cache nodes.
     *
     * This method is invoked by the store before the call to {@see save()} with the collection of cache nodes marked as deleted by a managed object context.
     * You can override this method to track the nodes which will not be made persistent in the {@see save()} method.
     * You should not invoke this method directly in a subclass.
     * @param Set<AtomicStoreCacheNode> $cacheNodes The set of cache nodes to remove.
     */
    public function willRemoveCacheNodes(Set $cacheNodes): void
    {
    }

    /**
     * Saves the cache nodes.
     *
     * You override this method to make persistent the necessary information from the cache nodes to the URL specified for the receiver.
     * You must override this method.
     * @return bool
     * @throws Exception
     */
    public function save(): bool
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Returns the set of cache nodes registered with the receiver.
     *
     * You should modify this collection using {@see addCacheNodes()} and {@see willRemoveCacheNodes()}.
     * @return Set<AtomicStoreCacheNode> The set of cache nodes registered with the receiver.
     */
    public function cacheNodes(): Set
    {
        return new Set($this->nodeCache->values);
    }

    /**
     * Returns the cache node for a given managed object ID.
     *
     * This method is normally used by cache nodes to locate related cache nodes (by relationships).
     * @param ManagedObjectID $objectID A managed object ID.
     * @return AtomicStoreCacheNode|null The cache node for objectID.
     */
    public function cacheNode(ManagedObjectID $objectID): ?AtomicStoreCacheNode
    {
        return $this->nodeCache[(string)$objectID];
    }
}
