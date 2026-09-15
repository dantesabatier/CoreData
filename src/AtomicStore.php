<?php

namespace Sabatier\CoreData;

use Exception;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionType;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\SortDescriptor;
use function Sabatier\Foundation\fatal_error;
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
    private Dictionary $nodeCache {
        get => $this->nodeCache ??= new Dictionary();
    }
    private int $nextReference = NotFound;

    private function addObject(ManagedObject $object): void
    {
        $key = (string)$object->objectID;
        if ($this->nodeCache[$key]) {
            return;
        }
        $cacheNode = $this->newCacheNode($object);
        $this->nodeCache[$key] = $cacheNode;
        $this->updateObject($object);
    }

    private function updateObject(ManagedObject $object): void
    {
        $cacheNode = $this->cacheNode($object->objectID) ?? fatal_error("Invalid argument: object \"$object\" does not exist in the store");
        $snapshot = $cacheNode->propertyCache;
        // The node says which it is: one read from the file carries a stable faulting state, and its values are the baseline optimistic locking compares against. A node just built for an object being inserted carries none and has nothing to be the baseline of.
        if ($snapshot[ManagedObjectFaultingStateKey] === ManagedObjectFaultingStateStable) {
            $object->updateFromSnapshot($snapshot);
        } else {
            $object->updateFromRefreshSnapshot($snapshot);
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
            $value->insert($object);
            $dictionary[$key] = $value;
        }
        return $dictionary;
    }

    private function resolvePredicateObjectReferences(Predicate $predicate, EntityDescription $entity): Predicate
    {
        if ($predicate instanceof ComparisonPredicate) {
            $expressions = new ArrayClass([$predicate->rightExpression, $predicate->leftExpression]);
            if (($keyPathExpression = $expressions->first(fn(Expression $expression): bool => $expression->expressionType === ExpressionType::keyPath && str_ends_with($expression->keyPath, ManagedObjectObjectIDKey))) && ($constantValueExpression = $expressions->first(fn(Expression $expression): bool => !$expression->isEqual($keyPathExpression))) && !$constantValueExpression->constantValue instanceof ManagedObjectID) {
                $expressionForConstantValue = Expression::expressionForConstantValue($this->objectID($entity, $constantValueExpression->constantValue));
                $rightExpression = $keyPathExpression === $predicate->rightExpression ? $keyPathExpression : $expressionForConstantValue;
                $leftExpression = $constantValueExpression === $predicate->leftExpression ? $expressionForConstantValue : $keyPathExpression;
                return new ComparisonPredicate($rightExpression, $leftExpression, $predicate->predicateOperatorType, $predicate->comparisonPredicateModifier, $predicate->options);
            }
            return $predicate;
        }
        if ($predicate instanceof CompoundPredicate) {
            return new CompoundPredicate($predicate->compoundPredicateType, $predicate->subpredicates->map(fn(Predicate $subpredicate): Predicate => $this->resolvePredicateObjectReferences($subpredicate, $entity)));
        }
        return $predicate;
    }

    /**
     * @param FetchRequest<mixed> $request
     * @return ArrayClass<AtomicStoreCacheNode>
     */
    private function cacheNodesForFetchRequest(FetchRequest $request): ArrayClass
    {
        $entity = $request->entity ?? fatal_error("Invalid fetch request: missing entity");
        return $this->nodeCache->values->filter(fn(AtomicStoreCacheNode $cacheNode): bool => $request->includesSubentities ? $cacheNode->objectID->entity->isKindOf($entity) : $cacheNode->objectID->entity->isEqual($entity));
    }

    /**
     * @param ArrayClass<AtomicStoreCacheNode> $cacheNodes
     * @return ArrayClass<ManagedObject>
     */
    private function managedObjectsFromCacheNodes(ArrayClass $cacheNodes, ManagedObjectContext $context): ArrayClass
    {
        return $cacheNodes->map(function (AtomicStoreCacheNode $cacheNode) use ($context): ManagedObject {
            $object = $context->object($cacheNode->objectID);
            if ($object->isAwakeFromFetch) {
                return $object;
            }
            // Hydration here reads the live cache rather than snapshots already taken off a statement, so resolving a relationship runs a fetch of its own that lands back in this method for the same object. Marking the object before applying the snapshot is what ends that; SQLFetchRequestContext needs no such guard because its snapshots are closed over before any object is built.
            $object->isAwakeFromFetch = true;
            // A fetch can be issued mid-save by conflict detection, where re-applying the stored snapshot would resurrect the values the save is about to remove.
            if (!$context->updatedObjects->containsElement($object) && !$context->deletedObjects->containsElement($object)) {
                $object->isSuppressingChangeNotifications = true;
                $this->updateObject($object);
                $object->isSuppressingChangeNotifications = false;
            }
            $object->awakeFromFetch();
            return $object;
        });
    }

    private function executeFetchRequest(FetchRequest $request, ManagedObjectContext $context): ArrayClass
    {
        assert($request->entity !== null, "Invalid fetch request: missing entity");
        $resultType = $request->resultType;
        /** @var ArrayClass<PropertyDescription|string> $propertiesToGroupBy */
        $propertiesToGroupBy = $request->propertiesToGroupBy ?? new ArrayClass();
        if (!$propertiesToGroupBy->isEmpty && $resultType !== FetchRequestResultType::dictionaryResultType) {
            fatal_error(sprintf("Invalid fetch request: GROUP BY requires %s, %s given", human_readable_value(FetchRequestResultType::dictionaryResultType), human_readable_value($request->resultType)));
        }
        $objects = $this->managedObjectsFromCacheNodes($this->cacheNodesForFetchRequest($request), $context);
        $predicate = $request->predicate;
        if ($predicate) {
            $predicate = $this->resolvePredicateObjectReferences($predicate, $request->entity);
        }
        if ($resultType === FetchRequestResultType::managedObjectResultType) {
            if ($predicate) {
                $objects = $objects->filtered($predicate);
            }
            $objects = $objects->map(fn(ManagedObject $object): ManagedObject => $object->serialized($request->serialization));
            if ($descriptors = $request->sortDescriptors) {
                $objects = $objects->sorted($descriptors);
            }
        } elseif ($resultType === FetchRequestResultType::managedObjectIDResultType) {
            if ($predicate) {
                $objects = $objects->filtered($predicate);
            }
            if ($descriptors = $request->sortDescriptors) {
                $objects = $objects->sorted($descriptors);
            }
            $objects = $objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID);
        } elseif ($resultType === FetchRequestResultType::dictionaryResultType) {
            if (!$propertiesToGroupBy->isEmpty) {
                /** @var Dictionary<ArrayClass<ManagedObject>> $dictionary */
                $dictionary = new Dictionary();
                foreach ($objects as $object) {
                    $keyParts = $propertiesToGroupBy->map(fn(PropertyDescription|string $property): mixed => $object->valueForKey($property instanceof PropertyDescription ? $property->name : $property));
                    $groupKey = $keyParts->description;
                    $groupObjects = $dictionary[$groupKey];
                    if ($groupObjects instanceof ArrayClass) {
                        $groupObjects->append($object);
                    } else {
                        $dictionary[$groupKey] = new ArrayClass([$object]);
                    }
                }
                if ($havingPredicate = $request->havingPredicate) {
                    $dictionary = $dictionary->filter(fn(ArrayClass $groupObjects): bool => $groupObjects->contains(fn(ManagedObject $obj): bool => $havingPredicate->evaluate($obj)));
                }
                /** @var ArrayClass<ManagedObject> $objects */
                $objects = new ArrayClass($dictionary->joined());
            }
            if ($predicate) {
                $objects = $objects->filtered($predicate);
            }
            $objects = $objects->map(fn(ManagedObject $object): Dictionary => $object->jsonSerialize());
            if (($propertiesToFetch = $request->propertiesToFetch) && !$propertiesToFetch->isEmpty) {
                /** @var ArrayClass<ExpressionDescription> $expressionDescriptions */
                $expressionDescriptions = $propertiesToFetch->filter(fn(PropertyDescription|string $property): bool => $property instanceof ExpressionDescription);
                foreach ($expressionDescriptions as $expressionDescription) {
                    if (!($expression = $expressionDescription->expression)) {
                        continue;
                    }
                    foreach ($objects as $object) {
                        $object[$expressionDescription->name] = ManagedObject::coercedValue($expression->expressionValue(new ArrayClass([$object])), $expressionDescription->resultType);
                    }
                }
                $keys = $propertiesToFetch->map(fn(PropertyDescription|string $property): string => $property instanceof PropertyDescription ? $property->name : $property);
                $keys->insertAt(ManagedObjectObjectIDKey, 0);
                $keys->insertAt(ManagedObjectEntityNameKey, 1);
                foreach ($objects as $object) {
                    foreach ($object->keys as $key) {
                        if ($keys->containsElement($key)) {
                            continue;
                        }
                        $object->removeValueForKey($key);
                    }
                }
            }
            if ($descriptors = $request->sortDescriptors) {
                $objects = $objects->sorted($descriptors);
            }
        } else {
            if ($predicate) {
                $objects = $objects->filtered($predicate);
            }
            return new ArrayClass([new Number($objects->count)]);
        }
        // fetchOffset then fetchLimit apply to the row-returning result types only, after filtering and sorting; the count result type reports the full total and returns above. A fetchLimit of 0 means no limit (see FetchRequest::$fetchLimit).
        if ($fetchOffset = $request->fetchOffset) {
            $objects = new ArrayClass($objects->dropFirst($fetchOffset));
        }
        if ($fetchLimit = $request->fetchLimit) {
            return new ArrayClass($objects->prefix($fetchLimit));
        }
        return $objects;
    }

    private function executeRefreshRequest(RefreshRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->groupedObjects($request->refreshObjects)->flatMap(function (Set $objects, string $key) use ($context): Set|ArrayClass {
            if (!$objects->isEmpty && ($entity = $this->persistentStoreCoordinator->managedObjectModel->entitiesByName[$key])) {
                $fetchRequest = new FetchRequest();
                $fetchRequest->entity = $entity;
                $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(ManagedObjectObjectIDKey), Expression::expressionForConstantValue($objects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID)), PredicateOperatorType::in);
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
            fatal_error("Cannot modify a read only persistent store");
        }
        if ($deletedObjects = $request->deletedObjects) {
            /** @var Set<AtomicStoreCacheNode> $deletedNodes */
            $deletedNodes = new Set();
            foreach ($deletedObjects as $deletedObject) {
                $deletedNodes->insert($this->cacheNode($deletedObject->objectID) ?? fatal_error("Unable to delete an uncached object $deletedObject"));
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
                $this->updateCacheNode($this->cacheNode($updatedObject->objectID) ?? fatal_error("Unable to update an uncached object $updatedObject"), $updatedObject);
            }
        }
        $this->save();
        $this->storeNextReferenceInMetadata();
        return new ArrayClass();
    }

    #[Override]
    public function execute(PersistentStoreRequest $request, ManagedObjectContext $context): ArrayClass
    {
        if ($request instanceof FetchRequest) {
            return $this->executeFetchRequest($request, $context);
        }
        if ($request instanceof RefreshRequest) {
            return $this->executeRefreshRequest($request, $context);
        }
        if ($request instanceof SaveChangesRequest) {
            return $this->executeSaveChangesRequest($request, $context);
        }
        return new ArrayClass();
    }

    #[Override]
    public function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): AtomicStoreCacheNode
    {
        // Faults must be fulfilled from the registered node: newCacheNode is reserved for newly inserted objects and, in stores like XML, appends a duplicate backing element.
        if ($cacheNode = $this->cacheNode($objectID)) {
            return $cacheNode;
        }
        $object = $context->existingObject($objectID) ?? fatal_error("Unable to find object with ID $objectID");
        return $this->newCacheNode($object);
    }

    #[Override]
    public function newValueForRelationship(RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass|ManagedObjectID|Nil
    {
        $entity = $objectID->entity;
        $destinationEntity = $relationship->destinationEntity;
        $inverseRelationship = $relationship->inverseRelationship;
        if (!$entity->isAbstract && !$destinationEntity->isAbstract && !$objectID->isTemporaryID) {
            if ($relationship->isToMany) {
                if ($inverseRelationship->isToMany) {
                    return $this->nodeCache->filter(fn(AtomicStoreCacheNode $node): bool => $node->objectID->entity->isKindOf($destinationEntity) && $node->valueForKey($inverseRelationship->name)?->containsElement($objectID))->map(fn(AtomicStoreCacheNode $node): ManagedObjectID => $node->objectID);
                }
                return $this->nodeCache->filter(fn(AtomicStoreCacheNode $node): bool => $node->objectID->entity->isKindOf($destinationEntity) && $node->valueForKey($inverseRelationship->name)?->isEqual($objectID))->map(fn(AtomicStoreCacheNode $node): ManagedObjectID => $node->objectID);
            }
            if (!$inverseRelationship->isToMany) {
                return $this->nodeCache->first(fn(AtomicStoreCacheNode $node): bool => $node->objectID->entity->isKindOf($destinationEntity) && $node->valueForKey($inverseRelationship->name)?->isEqual($objectID))?->objectID ?? Nil::nil();
            }
            // A to-one whose inverse is to-many cannot be found by scanning the destination nodes the way the branches above do: the inverse holds a collection, and the owning side is the only one that records the single ID. Read it from this object's own node instead. Without this the ordinary to-one/to-many pair fell through to the Nil below, so a relationship re-faulted by FaultHandler::fulfillFault could never be resolved again.
            $value = $this->cacheNode($objectID)?->valueForKey($relationship->name);
            if ($value instanceof ManagedObjectID) {
                return $value;
            }
            if ($value instanceof ManagedObject) {
                return $value->objectID;
            }
            return Nil::nil();
        }
        if ($relationship->isToMany && !$relationship->isOptional) {
            return new ArrayClass();
        }
        return Nil::nil();
    }

    #[Override]
    public function newOrderedRelationshipInformationForRelationship(RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass|Nil
    {
        $columnName = $relationship->destinationEntity->attributesByName->first?->name ?? ManagedObjectObjectIDKey;
        /** @var ArrayClass<ManagedObjectID>|Nil $newValue */
        $newValue = $this->newValueForRelationship($relationship, $objectID, $context);
        if ($newValue instanceof Nil) {
            return $newValue;
        }
        if ($newValue->isEmpty) {
            return $newValue;
        }
        return $newValue->map(fn(ManagedObjectID $objectID): AtomicStoreCacheNode => $this->cacheNode($objectID) ?? fatal_error("No cache node found for object ID: $objectID"))->sorted([new SortDescriptor($columnName)])->map(fn(AtomicStoreCacheNode $node) => $node->objectID);
    }

    #[Override]
    public function newValueForFetchedProperty(FetchedPropertyDescription $fetchedProperty, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass
    {
        $fetchRequest = $fetchedProperty->fetchRequest ?? fatal_error("Fetched property \"$fetchedProperty->name\" fetchRequest cannot be null.");
        $entityName = $fetchRequest->entityName ?? fatal_error("FetchRequest for fetched property \"$fetchedProperty->name\" entityName cannot be null.");
        $predicate = $fetchRequest->predicate ?? fatal_error("FetchRequest for fetched property \"$fetchedProperty->name\" predicate cannot be null.");
        /** @var FetchRequest<ManagedObjectID> $request */
        $request = clone($fetchRequest, [
            "entity" => EntityDescription::entity($entityName, $context),
            "predicate" => $predicate->withSubstitutionVariables(new Dictionary(["\$FETCH_SOURCE" => $context->object($objectID), "\$FETCHED_PROPERTY" => $fetchedProperty])),
            "resultType" => FetchRequestResultType::managedObjectIDResultType,
        ]);
        return $this->executeFetchRequest($request, $context);
    }

    #[Override]
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
    #[Override]
    public function load(): bool
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * Registers a set of cache nodes with the receiver.
     *
     * You should invoke this method in a subclass during the call to load() to register the loaded information with the store.
     * @param Set<AtomicStoreCacheNode> $cacheNodes A set of cache nodes.
     */
    public function addCacheNodes(Set $cacheNodes): void
    {
        $cacheNodes->forEach(fn(AtomicStoreCacheNode $cacheNode) => $this->nodeCache[(string)$cacheNode->objectID] = $cacheNode);
    }

    /**
     * Returns a new cache node for a given managed object.
     *
     * This method is invoked by the framework during a save operation, once for each newly inserted managed object.
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
     * You override this method in a subclass to take the information from managedObject and update $node.
     * You must override this method.
     * @param AtomicStoreCacheNode $node The cache node to update.
     * @param ManagedObject $object The managed object with which to update $node.
     */
    public function updateCacheNode(AtomicStoreCacheNode $node, ManagedObject $object): void
    {
    }

    /**
     * Method invoked before the store removes the given collection of cache nodes.
     *
     * The store invokes this method before the call to {@see save()} with the collection of cache nodes marked as deleted by a managed object context.
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
