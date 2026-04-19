<?php /** @noinspection PhpInternalEntityUsedInspection */

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
use Sabatier\Foundation\Sequence;
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
            $statement = SQLStatement::merging($entities->map(fn(SQLEntity $entity): SQLStatement => new SQLStatement("ALTER TABLE IF EXISTS `$entity->tableName` AUTO_INCREMENT = 0")));
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
            if ($requestContext instanceof SQLBatchUpdateRequestContext && $requestContext->request->resultType === BatchUpdateRequestResultType::objectIDs) {
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
                if (($deletedObjects = $requestContext->request->deletedObjects) && !$deletedObjects->isEmpty) {
                    $this->recomputePrimaryKeyMaxForEntities(new ArrayClass($deletedObjects->compactMap(fn(ManagedObject $object): ?SQLEntity => $this->model->entitiesByName[$object->entity->name])));
                    $this->rowCache->deleteSnapshots(new ArrayClass($deletedObjects->map(fn(ManagedObject $deletedObject): ManagedObjectID => $deletedObject->objectID)));
                }
                if ($updatedObjects = $requestContext->request->updatedObjects) {
                    /** @var Dictionary<Dictionary<mixed>> $snapshotsToUpdate */
                    $snapshotsToUpdate = new Dictionary();
                    /** @var ArrayClass<ManagedObjectID> $deletedObjectIDs */
                    $deletedObjectIDs = new ArrayClass();
                    foreach ($updatedObjects as $updatedObject) {
                        $objectID = $updatedObject->objectID;
                        if ($snapshot = $updatedObject->lastSnapshot) {
                            $snapshotsToUpdate[$objectID->uriRepresentation()->absoluteString] = $objectID->entity->sanitizeSnapshot($snapshot);
                            continue;
                        }
                        $deletedObjectIDs->append($objectID);
                    }
                    if (!$snapshotsToUpdate->isEmpty) {
                        $this->rowCache->setSnapshots($snapshotsToUpdate);
                    }
                    if (!$deletedObjectIDs->isEmpty) {
                        $this->rowCache->deleteSnapshots($deletedObjectIDs);
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
                $missingIDs = $managedObjectIDs->filter(fn(ManagedObjectID $objectID): bool => $context->isFaultOrUnregistered($objectID) && !$this->rowCache->hasSnapshot($objectID));
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
                        function (Dictionary $snapshots, Dictionary $snapshot) use ($entity): Dictionary {
                            $snapshots[$this->objectID($entity, $snapshot[ManagedObjectObjectIDKey])->uriRepresentation()->absoluteString] = $entity->sanitizeSnapshot($snapshot);
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
        if ($shouldCache && ($queryResultValue = match ($request->resultType) {
                FetchRequestResultType::managedObjectResultType => $result->map(fn(ManagedObject $object): string => $object->objectID->uriRepresentation()->absoluteString),
                FetchRequestResultType::managedObjectIDResultType => $result->map(fn(ManagedObjectID $objectID): string => $objectID->uriRepresentation()->absoluteString),
                default => false
            }) && $queryResultValue instanceof Sequence) {
            $this->rowCache->setSnapshot(new Dictionary([ManagedObjectQueryResultKey => $queryResultValue->array, ManagedObjectQueryResultGenerationKey => $expectedToken]), $queryID, $this->stalenessInterval);
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
            $object->updateFromRefreshSnapshot($snapshot);
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
        if ($snapshot = $this->rowCache->snapshot($objectID)) {
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
        if ($cached = $this->rowCache->snapshot($objectID, $relationship)) {
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
        $requestContext = new SQLRelationshipFaultRequestContext($objectID, $relationship, $context, $this);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        $result = $requestContext->result;
        $result instanceof ArrayClass || $result instanceof ManagedObjectID || $result instanceof Nil ?: $result
                |> typeof(...)
                |> (fn(string $x): string => sprintf("invalid argument: %s(%s, %s) expecting \"%s|%s|%s\", \"%s\" given", __FUNCTION__, $relationship->name, $objectID->entityName, Sequence::class, ManagedObjectID::class, Nil::class, $x))
                |> fatal_error(...);
        /** @var list<string>|string|Nil $propertyResultValue */
        $propertyResultValue = $result;
        if ($result instanceof ArrayClass) {
            $propertyResultValue = $result->map(fn(ManagedObjectID $objectID): string => $objectID->uriRepresentation()->absoluteString)->array;
        } elseif ($result instanceof ManagedObjectID) {
            $propertyResultValue = $result->uriRepresentation()->absoluteString;
        }
        $this->rowCache->setSnapshot(new Dictionary([ManagedObjectPropertyResultKey => $propertyResultValue]), $objectID, $this->stalenessInterval, $relationship);
        return $result;
    }

    /**
     * @throws Exception
     */
    #[Override]
    public function newValueForFetchedProperty(FetchedPropertyDescription $fetchedProperty, ManagedObjectID $objectID, ManagedObjectContext $context): ArrayClass
    {
        if ($cached = $this->rowCache->snapshot($objectID, $fetchedProperty)) {
            /** @var list<string> $value */
            $value = $cached[ManagedObjectPropertyResultKey];
            return new ArrayClass($value)->map(fn(string $string) => $this->managedObjectID(new URL($string)));
        }
        $requestContext = new SQLFetchedPropertyFaultRequestContext($objectID, $fetchedProperty, $context, $this);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        /** @var ArrayClass<ManagedObject> $result */
        $result = $requestContext->result;
        /** @var list<string> $propertyResultValue */
        $propertyResultValue = $result->map(fn(ManagedObjectID $objectID): string => $objectID->uriRepresentation()->absoluteString)->array;
        $this->rowCache->setSnapshot(new Dictionary([ManagedObjectPropertyResultKey => $propertyResultValue]), $objectID, $this->stalenessInterval, $fetchedProperty);
        return $result;
    }

    /**
     * @param ArrayClass<ManagedObjectID> $objectIDs
     * @param QueryGenerationToken|null $generation
     */
    #[Override]
    public function managedObjectContextDidUnregisterObjectsWithIDs(ArrayClass $objectIDs, ?QueryGenerationToken $generation): void
    {
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
