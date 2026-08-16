<?php

/** @noinspection PhpInternalEntityUsedInspection */

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:10
 */

namespace Sabatier\CoreData;

use Exception;
use Override;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\NotificationCenter;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\typeof;

/** @internal */
final class SQLCore extends IncrementalStore
{
    public static SQLDebugLevel $debugLevel = SQLDebugLevel::none;
    /** @var class-string<MigrationManager> */
    #[Override]
    public static string $migrationManagerClass = SQLInPlaceMigrationManager::class;
    /** @var class-string<SnapshotMapper> */
    #[Override]
    public static string $snapshotMapperClass = SQLSnapshotMapper::class;
    public static bool $debugColorOutputDefault = false;
    #[Override]
    public string $type {
        get => SQLStoreType;
    }
    private(set) SQLModel $model {
        get => $this->model ??= new SQLModel($this->persistentStoreCoordinator->managedObjectModel, $this->configurationName);
    }
    private(set) SQLAdapter $adapter {
        get => $this->adapter ??= new SQLAdapter($this);
    }
    private(set) SQLConnection $schemaValidationConnection {
        get => $this->schemaValidationConnection ??= new SQLConnection($this->adapter);
    }
    private(set) SQLConnection $queryGenerationTrackingConnection {
        get => $this->queryGenerationTrackingConnection ??= new SQLConnection($this->adapter);
    }
    /** @var Dictionary<int> */
    private Dictionary $maxPrimaryKeys {
        get => $this->maxPrimaryKeys ??= new Dictionary();
    }
    private(set) ?PersistentHistoryToken $remoteNotificationToken = null;
    private int $currentGeneration {
        get => $this->currentGeneration ??= $this->rowCache->currentGenerationForStore($this->identifier);
    }
    private int $storeGeneration {
        get => $this->storeGeneration ??= $this->queryGenerationTrackingConnection->storeOrigin;
    }

    public function __construct(PersistentStoreCoordinator $coordinator, string $configurationName, URL $url, ?Dictionary $options = null)
    {
        parent::__construct($coordinator, $configurationName, $url, $options);
        $this->addPersistentHistoryEntities();
    }

    /**
     * @throws Exception
     */
    #[Override]
    public static function cachedModelForPersistentStoreWithURL(URL $url, ?Dictionary $options = null): ?ManagedObjectModel
    {
        $connection = new SQLConnection();
        if ($connection->hasCachedModelTable) {
            return $connection->cachedModel;
        }
        return null;
    }

    /**
     * @param URL $url
     * @param Dictionary<mixed>|null $options
     * @return bool
     * @throws Exception
     */
    #[Override]
    public static function destroyPersistentStoreAtURL(URL $url, ?Dictionary $options = null): bool
    {
        return SQLConnection::destroyPersistentStoreAtURL($url, $options);
    }

    /**
     * @throws Exception
     */
    #[Override]
    public static function replacePersistentStoreAtURL(URL $destinationURL, ?Dictionary $destinationOptions, URL $sourceURL, ?Dictionary $sourceOptions): bool
    {
        return SQLConnection::replacePersistentStoreAtURL($destinationURL, $destinationOptions, $sourceURL, $sourceOptions);
    }

    #[Override]
    public static function metadataForPersistentStore(URL $url): Dictionary
    {
        $connection = new SQLConnection();
        if ($connection->hasMetadataTable && ($metadata = $connection->fetchMetadata())) {
            return $metadata;
        }
        return new Dictionary([StoreTypeKey => SQLStoreType]);
    }

    #[Override]
    public static function setMetadata(?Dictionary $metadata, URL $url): bool
    {
        $metadata ??= new Dictionary([StoreTypeKey => SQLStoreType, StoreUUIDKey => new UUID()->uuidString]);
        $connection = new SQLConnection();
        $connection->saveMetadata($metadata);
        return true;
    }

    #[Override]
    public function loadMetadata(): bool
    {
        if ($metadata = $this->queryGenerationTrackingConnection->fetchMetadata()) {
            $this->metadata = $metadata;
            $this->identifier = $metadata[StoreUUIDKey];
        }
        return true;
    }

    #[Override]
    public function willRemove(PersistentStoreCoordinator $coordinator): void
    {
    }

    private function addPersistentHistoryEntities(): void
    {
        $managedObjectModel = $this->persistentStoreCoordinator->managedObjectModel;
        if (!$managedObjectModel->entitiesByName["PersistentHistoryTransaction"]) {
            $reflectionClass = new ReflectionClass(PersistentHistoryTransaction::class);
            $entityDescription = new EntityDescription();
            $entityDescription->name = "PersistentHistoryTransaction";
            $entityDescription->isPersistentHistoryEntity = true;
            $entityDescription->properties = new ArrayClass($reflectionClass->getProperties())->compactMap(function (ReflectionProperty $property) use ($entityDescription): ?PropertyDescription {
                if ($property->isStatic()) {
                    return null;
                }
                $name = $property->name;
                if (match ($name) {
                    "token", "transactionNumber", "description", "associatedValues", "hash", "class", "superclass", "debugDescription", "canonicalDescription" => true,
                    default => false
                }) {
                    return null;
                }
                /** @var ReflectionNamedType $reflectionType */
                $reflectionType = $property->getType();
                $type = $reflectionType->getName();
                if ($type === ArrayClass::class) {
                    $relationship = new RelationshipDescription();
                    $relationship->name = $name;
                    $relationship->entity = $entityDescription;
                    $relationship->isToMany = true;
                    $relationship->deleteRule = DeleteRule::cascadeDeleteRule;
                    $relationship->lazyInverseRelationshipName = "transaction";
                    $relationship->lazyDestinationEntityName = "PersistentHistoryChange";
                    return $relationship;
                }
                $attribute = new AttributeDescription();
                $attribute->name = $name;
                $attribute->entity = $entityDescription;
                if ($type === "string") {
                    $attribute->type = AttributeType::string;
                } elseif ($type === "int") {
                    $attribute->type = AttributeType::integer64;
                    $attribute->isOptional = false;
                } elseif ($type === Date::class) {
                    $attribute->type = AttributeType::date;
                    $attribute->isOptional = false;
                } elseif ($type === PersistentHistoryToken::class) {
                    $attribute->type = AttributeType::transformable;
                }
                return $attribute;
            });
            $managedObjectModel->addEntity($entityDescription);
            PersistentHistoryTransaction::$entityDescription = $entityDescription;
        }
        if (!$managedObjectModel->entitiesByName["PersistentHistoryChange"]) {
            $reflectionClass = new ReflectionClass(PersistentHistoryChange::class);
            $entityDescription = new EntityDescription();
            $entityDescription->name = "PersistentHistoryChange";
            $entityDescription->isPersistentHistoryEntity = true;
            $entityDescription->properties = new ArrayClass($reflectionClass->getProperties())->compactMap(function (ReflectionProperty $property) use ($entityDescription): ?PropertyDescription {
                if ($property->isStatic()) {
                    return null;
                }
                $name = $property->name;
                if (match ($name) {
                    "changeID", "description", "associatedValues", "hash", "class", "superclass", "debugDescription", "canonicalDescription" => true,
                    default => false
                }) {
                    return null;
                }
                /** @var ReflectionNamedType $reflectionType */
                $reflectionType = $property->getType();
                $type = $reflectionType->getName();
                if ($type === PersistentHistoryTransaction::class) {
                    $relationship = new RelationshipDescription();
                    $relationship->name = $name;
                    $relationship->entity = $entityDescription;
                    $relationship->lazyInverseRelationshipName = "changes";
                    $relationship->lazyDestinationEntityName = "PersistentHistoryTransaction";
                    return $relationship;
                }
                $attribute = new AttributeDescription();
                $attribute->name = $name;
                $attribute->entity = $entityDescription;
                if ($type === "string") {
                    $attribute->type = AttributeType::string;
                } elseif ($type === "int") {
                    $attribute->type = AttributeType::integer16;
                    $attribute->isOptional = false;
                } elseif ($type === Date::class) {
                    $attribute->type = AttributeType::date;
                    $attribute->isOptional = false;
                } elseif ($type === ManagedObjectID::class) {
                    $attribute->type = AttributeType::objectID;
                    $attribute->isOptional = false;
                } elseif ($type === Dictionary::class || $type === Set::class) {
                    $attribute->type = AttributeType::transformable;
                } elseif ($type === PersistentHistoryChangeType::class) {
                    $attribute->type = AttributeType::integer16;
                    $attribute->isOptional = false;
                }
                return $attribute;
            });
            $managedObjectModel->addEntity($entityDescription);
            PersistentHistoryChange::$entityDescription = $entityDescription;
        }
    }

    /**
     * @param ArrayClass<SQLEntity> $entities
     * @throws Exception
     */
    public function recomputePrimaryKeyMaxForEntities(ArrayClass $entities): void
    {
        if (!$entities->isEmpty) {
            $entities->appendContentsOf($entities->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->entitySpecificRelationships->filter(fn(SQLRelationship $relationship): bool => $relationship->relationshipDescription->deleteRule === DeleteRule::cascadeDeleteRule))->map(fn(SQLRelationship $relationship): SQLEntity => $relationship->destinationEntity));
            /** @var ArrayClass<SQLEntity> $entities */
            $entities = new ArrayClass(new Set($entities->compactMap(fn(SQLEntity $entity): ?SQLEntity => $entity->isRootEntity ? $entity : $entity->rootEntity)));
            $statement = $this->adapter->newResetAutoIncrementStatement($entities);
            $this->queryGenerationTrackingConnection->execute($statement);
            $this->maxPrimaryKeys->removeAll(fn(int $pk, string $entityName): bool => $entities->contains(fn(SQLEntity $entity): bool => $entity->tableName === $entityName));
        }
    }

    /**
     * @throws Exception
     */
    private function postChangeNotificationWithTransactionID(Number $transactionID): void
    {
        if ($this->queryGenerationTrackingConnection->hasHistoryTransactionWithNumber($transactionID)) {
            $this->remoteNotificationToken = new PersistentHistoryToken(new Dictionary([$this->identifier => $transactionID]));
            NotificationCenter::default()->postNotificationName(PersistentStoreRemoteChange, $this->persistentStoreCoordinator, new Dictionary([StoreUUIDKey => $this->identifier, PersistentStoreURLKey => $this->url, PersistentHistoryTokenKey => $this->remoteNotificationToken]));
        }
    }

    /**
     * @throws Exception
     */
    private function processRequestContext(SQLStoreRequestContext $requestContext): mixed
    {
        !($requestContext->isWritingRequest && $this->isReadOnly) ?: fatal_error("Cannot modify a read only persistent store");
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        if ($requestContext->isWritingRequest) {
            if (!$requestContext->hasHistoryTracking && $this->options?->valueForKey(PersistentStoreRemoteChangeNotificationPostOptionKey) && $requestContext->transactionID->boolValue) {
                $this->postChangeNotificationWithTransactionID($requestContext->transactionID);
            }
            if ($requestContext instanceof SQLBatchInsertRequestContext && $requestContext->request->resultType === BatchInsertRequestResultType::objectIDs) {
                /** @var ArrayClass<ManagedObjectID> $insertedObjectIDs */
                $insertedObjectIDs = $requestContext->result;
                if (!$insertedObjectIDs->isEmpty) {
                    $this->rowCache->setSnapshots($insertedObjectIDs->reduce(new Dictionary(),
                        /**
                         * @param Dictionary<Dictionary<mixed>> $snapshots
                         * @param ManagedObjectID $objectID
                         * @return Dictionary<Dictionary<mixed>>
                         */
                        function (Dictionary $snapshots, ManagedObjectID $objectID) use ($requestContext): Dictionary {
                            $object = $requestContext->context->object($objectID);
                            if ($snapshot = $object->lastSnapshot) {
                                $snapshots[$object->objectID->uriRepresentation()->absoluteString] = $object->entity->sanitizeSnapshot($snapshot);
                            }
                            return $snapshots;
                        }));
                }
            } elseif ($requestContext instanceof SQLBatchUpdateRequestContext && $requestContext->request->resultType === BatchUpdateRequestResultType::objectIDs) {
                /** @var ArrayClass<ManagedObjectID> $updatedObjectIDs */
                $updatedObjectIDs = $requestContext->result;
                if (!$updatedObjectIDs->isEmpty) {
                    $this->rowCache->deleteSnapshots($updatedObjectIDs);
                }
            } elseif ($requestContext instanceof SQLBatchDeleteRequestContext) {
                /** @var ArrayClass<mixed> $result */
                $result = $requestContext->result;
                if (match ($requestContext->request->resultType) {
                    BatchDeleteRequestResultType::statusOnly => !$result->containsElement(false),
                    BatchDeleteRequestResultType::objectIDs => !$result->isEmpty,
                    BatchDeleteRequestResultType::count => (bool)$result->sum()
                }) {
                    /** @var SQLEntity $entity */
                    $entity = $this->model->entitiesByName[(string)$requestContext->fetchRequestForObjectsToDelete->entity?->name];
                    $this->recomputePrimaryKeyMaxForEntities(new ArrayClass([$entity]));
                    if ($requestContext->request->resultType === BatchDeleteRequestResultType::objectIDs) {
                        /** @var ArrayClass<ManagedObjectID> $deleteObjectIDs */
                        $deleteObjectIDs = $requestContext->result;
                        if (!$deleteObjectIDs->isEmpty) {
                            $this->rowCache->deleteSnapshots($deleteObjectIDs);
                        }
                    }
                }
            } elseif ($requestContext instanceof SQLSaveChangesRequestContext) {
                if (($insertedObjects = $requestContext->request->insertedObjects) && !$insertedObjects->isEmpty) {
                    $this->rowCache->setSnapshots($insertedObjects->reduce(new Dictionary(),
                        /**
                         * @param Dictionary<Dictionary<mixed>> $snapshots
                         * @param ManagedObject $object
                         * @return Dictionary<Dictionary<mixed>>
                         */
                        function (Dictionary $snapshots, ManagedObject $object): Dictionary {
                            if ($snapshot = $object->lastSnapshot) {
                                $snapshots[$object->objectID->uriRepresentation()->absoluteString] = $object->entity->sanitizeSnapshot($snapshot);
                            }
                            return $snapshots;
                        }), $this->stalenessInterval);
                }
                if (($deletedObjects = $requestContext->request->deletedObjects) && !$deletedObjects->isEmpty) {
                    $this->recomputePrimaryKeyMaxForEntities(new ArrayClass($deletedObjects->compactMap(fn(ManagedObject $object): ?SQLEntity => $this->model->entitiesByName[$object->entity->name])));
                    $this->rowCache->deleteSnapshots(new ArrayClass($deletedObjects->map(fn(ManagedObject $deletedObject): ManagedObjectID => $deletedObject->objectID)));
                }
                if (($updatedObjects = $requestContext->request->updatedObjects) && !$updatedObjects->isEmpty) {
                    /** @var Dictionary<Dictionary<mixed>> $snapshots */
                    $snapshots = new Dictionary();
                    /** @var Dictionary<ArrayClass<RelationshipDescription>> $relationshipSnapshots */
                    $relationshipSnapshots = new Dictionary();
                    foreach ($updatedObjects as $updatedObject) {
                        $uri = $updatedObject->objectID->uriRepresentation()->absoluteString;
                        $snapshot = $updatedObject->dictionaryWithValues(new ArrayClass([ManagedObjectObjectIDKey, ManagedObjectEntityNameKey, ManagedObjectVersionKey])->appendingContentsOf($updatedObject->modeledAttributes->filter(fn(AttributeDescription $attribute): bool => !($attribute instanceof DerivedAttributeDescription))->map(fn(AttributeDescription $attribute): string => $attribute->name)));
                        $snapshots[$uri] = $updatedObject->entity->sanitizeSnapshot($snapshot);
                        $changedValuesForCurrentEvent = $updatedObject->changedValuesForCurrentEvent();
                        foreach ($changedValuesForCurrentEvent->keys as $key) {
                            $relationship = $updatedObject->modeledRelationships[$key];
                            if ($relationship instanceof RelationshipDescription) {
                                $relationshipSnapshots[$uri] ??= new ArrayClass();
                                $relationshipSnapshots[$uri]->append($relationship);
                            }
                        }
                    }
                    if (!$snapshots->isEmpty) {
                        $this->rowCache->setSnapshots($snapshots, $this->stalenessInterval);
                    }
                    if (!$relationshipSnapshots->isEmpty) {
                        $this->rowCache->deletePropertySnapshots($relationshipSnapshots);
                    }
                }
            }
            $this->currentGeneration = $this->rowCache->advanceGenerationForStore($this->identifier);
        }
        return $requestContext->result;
    }

    /**
     * @throws Exception
     */
    private function processFetchRequest(FetchRequest $request, ManagedObjectContext $context): ArrayClass
    {
        $expectedToken = $context->queryGenerationToken ?? new QueryGenerationToken($this->identifier, $this->storeGeneration, $this->currentGeneration);
        $shouldCache = !$request->needsDistinct && match ($request->resultType) {
                FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType => true,
                default => false
            };
        if ($shouldCache && ($predicate = $request->predicate)) {
            $shouldCache = !new PredicateCacheAnalysis($predicate)->isRuntimeOnly;
        }
        /** @var EntityDescription $entity */
        $entity = $request->entity;
        $queryKey = $this->rowCache->queryKeyForRequest($request, $expectedToken);
        $queryID = $this->objectID($entity, $queryKey);
        if ($shouldCache && ($cached = $this->rowCache->snapshot($queryID))) {
            /** @var QueryGenerationToken|null $cachedToken */
            $cachedToken = $cached[ManagedObjectQueryResultGenerationKey];
            if ($expectedToken->isCompatible($cachedToken)) {
                /** @var list<string> $strings */
                $strings = $cached[ManagedObjectQueryResultKey];
                $managedObjectIDs = new ArrayClass($strings)->map(fn(string $string): ManagedObjectID => $this->managedObjectID(new URL($string)));
                if ($request->resultType === FetchRequestResultType::managedObjectIDResultType) {
                    return $managedObjectIDs;
                }
                // A snapshot cached by an earlier fetch only covers what that serialization asked for. Taking its mere existence as proof would leave the attributes this fetch needs reading as nulls, so its reach is checked and whichever falls short is refetched.
                $requestedAttributes = $request->serializationAttributeNames;
                $missingIDs = $managedObjectIDs->filter(function (ManagedObjectID $objectID) use ($context, $requestedAttributes): bool {
                    $snapshot = $this->rowCache->snapshot($objectID);
                    if ($snapshot === null) {
                        return $context->isFaultOrUnregistered($objectID);
                    }
                    return !$objectID->entity->snapshotCovers($snapshot, $requestedAttributes);
                });
                if (!$missingIDs->isEmpty) {
                    $faultRequestContext = new SQLBatchFaultRequestContext($missingIDs, $context, $this);
                    $faultRequestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
                    /** @var ArrayClass<Dictionary<mixed>> $snapshots */
                    $snapshots = $faultRequestContext->result;
                    $this->rowCache->setSnapshots($snapshots->reduce(new Dictionary(),
                        /**
                         * @param Dictionary<mixed> $snapshot
                         * @return Dictionary<mixed>
                         */
                        function (Dictionary $snapshots, Dictionary $snapshot) use ($entity, $context): Dictionary {
                            // A fetch of an abstract entity returns rows whose entity column names the concrete subentity, so the request's own entity cannot drive instantiation.
                            $entityName = $snapshot[ManagedObjectEntityNameKey] ?? $entity->name;
                            /** @var SQLEntity $rowEntity */
                            $rowEntity = $this->model->entitiesByName[$entityName] ?? fatal_error("Entity not found: $entityName");
                            $objectID = $this->objectID($rowEntity->entityDescription, $snapshot[$rowEntity->primaryKey->columnName]);
                            // The batch returns rows as dictionaries, so refreshing only the cache would leave the already registered object holding the incomplete values that prompted the refetch.
                            $object = $context->object($objectID);
                            $object->isSuppressingChangeNotifications = true;
                            $object->isSuppressingKVO = true;
                            $object->materializeFaultsFromSnapshot($snapshot);
                            $object->isSuppressingKVO = false;
                            $object->isSuppressingChangeNotifications = false;
                            $snapshots[$objectID->uriRepresentation()->absoluteString] = $rowEntity->entityDescription->sanitizeSnapshot($snapshot);
                            return $snapshots;
                        }), $this->stalenessInterval);
                }
                return $managedObjectIDs->map(fn(ManagedObjectID $objectID): ManagedObject => $context->object($objectID)->serialized($request->serialization));
            }
        }
        /** @var ArrayClass $result */
        $result = $this->processRequestContext(match ($request->resultType) {
            FetchRequestResultType::managedObjectResultType, FetchRequestResultType::managedObjectIDResultType, FetchRequestResultType::dictionaryResultType => new SQLFetchRequestContext($request, $context, $this),
            FetchRequestResultType::countResultType => new SQLCountRequestContext($request, $context, $this)
        });
        if ($shouldCache) {
            /** @var ArrayClass<string> $queryResultValue */
            $queryResultValue = new ArrayClass();
            if ($request->resultType === FetchRequestResultType::managedObjectResultType) {
                $queryResultValue = $result->map(fn(ManagedObject $object): string => $object->objectID->uriRepresentation()->absoluteString);
                $this->rowCache->setSnapshots($result->reduce(new Dictionary(),
                    /**
                     * @param Dictionary<Dictionary<mixed>> $snapshots
                     * @param ManagedObject $object
                     * @return Dictionary<Dictionary<mixed>>
                     */
                    function (Dictionary $snapshots, ManagedObject $object): Dictionary {
                        if ($snapshot = $object->lastSnapshot) {
                            $snapshots[$object->objectID->uriRepresentation()->absoluteString] = $object->entity->sanitizeSnapshot($snapshot);
                        }
                        return $snapshots;
                    }), $this->stalenessInterval);
            } elseif ($request->resultType === FetchRequestResultType::managedObjectIDResultType) {
                $queryResultValue = $result->map(fn(ManagedObjectID $objectID): string => $objectID->uriRepresentation()->absoluteString);
            }
            if (!$queryResultValue->isEmpty) {
                $this->rowCache->setSnapshot(new Dictionary([ManagedObjectQueryResultKey => $queryResultValue->array, ManagedObjectQueryResultGenerationKey => $expectedToken]), $queryID, $this->stalenessInterval);
            }
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    private function processRefreshObjects(RefreshRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return new ArrayClass($request->refreshObjects->map(function (ManagedObject $object) use ($context): ManagedObject {
            $snapshot = $this->processRequestContext(new SQLObjectFaultRequestContext($object->objectID, $context, $this));
            $object->isSuppressingKVO = true;
            $object->updateFromRefreshSnapshot($snapshot);
            $object->isSuppressingKVO = false;
            $object->awakeFromSnapshotEvents(SnapshotEventType::refresh);
            return $object;
        }));
    }

    /**
     * @throws Exception
     */
    private function processSaveChanges(SaveChangesRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->processRequestContext(new SQLSaveChangesRequestContext($request, $context, $this));
    }

    /**
     * @throws Exception
     */
    private function processBatchInsert(BatchInsertRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->processRequestContext(new SQLBatchInsertRequestContext($request, $context, $this));
    }

    /**
     * @throws Exception
     */
    private function processBatchUpdate(BatchUpdateRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->processRequestContext(new SQLBatchUpdateRequestContext($request, $context, $this));
    }

    /**
     * @throws Exception
     */
    private function processBatchDelete(BatchDeleteRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->processRequestContext(new SQLBatchDeleteRequestContext($request, $context, $this));
    }

    /**
     * @throws Exception
     */
    private function processChangeRequest(PersistentHistoryChangeRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->processRequestContext(new SQLPersistentHistoryChangeRequestContext($request, $context, $this));
    }

    #[Override]
    public function execute(PersistentStoreRequest $request, ManagedObjectContext $context): ArrayClass
    {
        if ($request instanceof FetchRequest) {
            return $this->processFetchRequest($request, $context);
        }
        if ($request instanceof RefreshRequest) {
            return $this->processRefreshObjects($request, $context);
        }
        if ($request instanceof SaveChangesRequest) {
            return $this->processSaveChanges($request, $context);
        }
        if ($request instanceof BatchInsertRequest) {
            return $this->processBatchInsert($request, $context);
        }
        if ($request instanceof BatchUpdateRequest) {
            return $this->processBatchUpdate($request, $context);
        }
        if ($request instanceof BatchDeleteRequest) {
            return $this->processBatchDelete($request, $context);
        }
        if ($request instanceof PersistentHistoryChangeRequest) {
            return $this->processChangeRequest($request, $context);
        }
        return new ArrayClass();
    }

    #[Override]
    public function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): IncrementalStoreNode
    {
        // Fulfilling a fault fills the whole object, so a partial snapshot is no use here: returning it would leave the attributes it does not cover as nulls and, once stored again, the gap would perpetuate itself.
        if (($snapshot = $this->rowCache->snapshot($objectID)) && $objectID->entity->snapshotCovers($snapshot, $objectID->entity->persistentAttributeNames)) {
            return new IncrementalStoreNode($objectID, $snapshot, $snapshot[ManagedObjectVersionKey] ?? 1);
        }
        $requestContext = new SQLObjectFaultRequestContext($objectID, $context, $this);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        /** @var Dictionary<mixed> $snapshot */
        $snapshot = $requestContext->result;
        $this->rowCache->setSnapshot($objectID->entity->sanitizeSnapshot($snapshot), $objectID, $this->stalenessInterval);
        return new IncrementalStoreNode($objectID, $snapshot);
    }

    /**
     * @throws Exception
     */
    #[Override]
    public function newValueForRelationship(RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass|ManagedObjectID|Nil
    {
        $expectedToken = $context->queryGenerationToken ?? new QueryGenerationToken($this->identifier, $this->storeGeneration, $this->currentGeneration);
        if ($cached = $this->rowCache->snapshot($objectID, $relationship)) {
            /** @var QueryGenerationToken|null $cachedToken */
            $cachedToken = $cached[ManagedObjectQueryResultGenerationKey];
            if ($expectedToken->isCompatible($cachedToken)) {
                /** @var list<string>|string|Nil $value */
                $value = $cached[ManagedObjectPropertyResultKey];
                if (is_array($value)) {
                    return new ArrayClass($value)->map(fn(string $string) => $this->managedObjectID(new URL($string)));
                }
                if (is_string($value)) {
                    return $this->managedObjectID(new URL($value));
                }
                return $value;
            }
        }
        $requestContext = new SQLRelationshipFaultRequestContext($objectID, $relationship, $context, $this);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        $result = $requestContext->result;
        $result instanceof ArrayClass || $result instanceof ManagedObjectID || $result instanceof Nil ?: $result
                |> typeof(...)
                |> (fn(string $x): string => sprintf("invalid argument: %s(%s, %s) expecting \"%s|%s|%s\", \"%s\" given", __FUNCTION__, $relationship->name, $objectID->entityName, ArrayClass::class, ManagedObjectID::class, Nil::class, $x))
                |> fatal_error(...);
        /** @var list<string>|string|Nil $propertyResultValue */
        $propertyResultValue = $result;
        if ($result instanceof ArrayClass) {
            $propertyResultValue = $result->map(fn(ManagedObjectID $objectID): string => $objectID->uriRepresentation()->absoluteString)->array;
        } elseif ($result instanceof ManagedObjectID) {
            $propertyResultValue = $result->uriRepresentation()->absoluteString;
        }
        $this->rowCache->setSnapshot(new Dictionary([ManagedObjectPropertyResultKey => $propertyResultValue, ManagedObjectQueryResultGenerationKey => $expectedToken]), $objectID, $this->stalenessInterval, $relationship);
        return $result;
    }

    #[Override]
    public function newOrderedRelationshipInformationForRelationship(RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass|Nil
    {
        /** @var SQLEntity $entity */
        $entity = $this->model->entitiesByName[$relationship->entity->name];
        $toMany = $entity->propertiesByName[$relationship->name];
        // The order lives in a column on the destination table, which only exists when the inverse is
        // to-one; a many-to-many is stored in a correlation table that keeps no position.
        if (!$toMany instanceof SQLToMany) {
            fatal_error(sprintf("%s->%s cannot be ordered: a relationship whose inverse is also to-many is stored in a correlation table, which has no column to keep the order in", $relationship->entity->name, $relationship->name));
        }
        $toOne = $toMany->inverseToOne;
        /** @var SQLAttribute $attribute */
        $attribute = $toOne->foreignOrderKey->entity->propertiesByName[$toOne->foreignOrderKey->columnName];
        $columnName = $attribute->columnName;
        /** @var ArrayClass<ManagedObjectID>|Nil $newValue */
        $newValue = $this->newValueForRelationship($relationship, $objectID, $context);
        if ($newValue instanceof Nil) {
            return $newValue;
        }
        if ($newValue->isEmpty) {
            return $newValue;
        }
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $toOne->entity->entityDescription;
        $requestContext = new SQLObjectIDSetFetchRequestContext($fetchRequest, $context, $this, $newValue, $columnName);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        return $requestContext->result;
    }

    #[Override]
    public function newValueForFetchedProperty(FetchedPropertyDescription $fetchedProperty, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass
    {
        $isSorted = $fetchedProperty->fetchRequest?->sortDescriptors !== null;
        $expectedToken = $context->queryGenerationToken ?? new QueryGenerationToken($this->identifier, $this->storeGeneration, $this->currentGeneration);
        if (!$isSorted && ($cached = $this->rowCache->snapshot($objectID, $fetchedProperty))) {
            /** @var QueryGenerationToken|null $cachedToken */
            $cachedToken = $cached[ManagedObjectQueryResultGenerationKey];
            if ($expectedToken->isCompatible($cachedToken)) {
                /** @var list<string> $value */
                $value = $cached[ManagedObjectPropertyResultKey];
                return new ArrayClass($value)->map(fn(string $string) => $this->managedObjectID(new URL($string)));
            }
        }
        $requestContext = new SQLFetchedPropertyFaultRequestContext($objectID, $fetchedProperty, $context, $this);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        /** @var ArrayClass<ManagedObjectID> $result */
        $result = $requestContext->result;
        if (!$isSorted) {
            /** @var list<string> $propertyResultValue */
            $propertyResultValue = $result->map(fn(ManagedObjectID $objectID): string => $objectID->uriRepresentation()->absoluteString)->array;
            $this->rowCache->setSnapshot(new Dictionary([ManagedObjectPropertyResultKey => $propertyResultValue, ManagedObjectQueryResultGenerationKey => $expectedToken]), $objectID, $this->stalenessInterval, $fetchedProperty);
        }
        return $result;
    }

    /**
     * @param ArrayClass<ManagedObjectID> $objectIDs
     * @param QueryGenerationToken|null $generation
     */
    #[Override]
    public function managedObjectContextDidUnregisterObjectsWithIDs(ArrayClass $objectIDs, ?QueryGenerationToken $generation): void
    {
        if ($objectIDs->isEmpty) {
            return;
        }
        $this->rowCache->deleteSnapshots($objectIDs);
        $this->currentGeneration = $this->rowCache->advanceGenerationForStore($this->identifier);
    }

    #[Override]
    public function newReferenceObject(ManagedObject $managedObject): int
    {
        /** @var SQLEntity $entity */
        $entity = $this->model->entity($managedObject->entity->name);
        $entityName = $entity->tableName;
        if (!$this->maxPrimaryKeys[$entityName]) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $this->maxPrimaryKeys[$entityName] = $this->queryGenerationTrackingConnection->fetchMaxPrimaryKey($entityName);
        }
        $this->maxPrimaryKeys[$entityName] += 1;
        return $this->maxPrimaryKeys[$entityName];
    }

    public function ensureDatabaseMatchesModel(): void
    {
    }

    #[Override]
    public function load(): bool
    {
        return $this->queryGenerationTrackingConnection->connect();
    }

    #[Override]
    public function unload(): bool
    {
        return $this->queryGenerationTrackingConnection->disconnect();
    }
}
