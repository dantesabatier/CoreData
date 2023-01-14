<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:10
 */

namespace Sabatier\CoreData;

use Exception;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\NotificationCenter;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/** @internal */
class SQLCore extends IncrementalStore
{
    public static int $debugDefault = 0;
    public static bool $coloredLoggingDefault = false;
    public string $type = SQLStoreType;
    public readonly SQLModel $model;
    public readonly SQLAdapter $adapter;
    public readonly SQLConnection $schemaValidationConnection;
    public readonly SQLConnection $queryGenerationTrackingConnection;
    /** @var Dictionary<int> */
    private Dictionary $maxPrimaryKeys;
    public ?PersistentHistoryToken $remoteNotificationToken = null;

    public function __construct(PersistentStoreCoordinator $coordinator, string $configurationName, URL $url, ?Dictionary $options = null)
    {
        parent::__construct($coordinator, $configurationName, $url, $options);
        unset($this->adapter);
        unset($this->schemaValidationConnection);
        unset($this->queryGenerationTrackingConnection);
        unset($this->maxPrimaryKeys);
        unset($this->model);
        $managedObjectModel = $this->persistentStoreCoordinator->managedObjectModel;
        if (!$managedObjectModel->entitiesByName["PersistentHistoryTransaction"]) {
            $reflectionClass = new ReflectionClass(PersistentHistoryTransaction::class);
            $entityDescription = new EntityDescription();
            $entityDescription->name = "PersistentHistoryTransaction";
            $entityDescription->isPersistentHistoryEntity = true;
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $entityDescription->properties = /** @phpstan-ignore-line */
                (new ArrayClass($reflectionClass->getProperties()))->compactMap(function (ReflectionProperty $property) use ($entityDescription): ?PropertyDescription {
                    if ($property->isStatic()) {
                        return null;
                    }
                    $name = $property->name;
                    if ($name == "token") {
                        return null;
                    }
                    if ($name == "transactionNumber") {
                        $name = "transactionID";
                    }
                    /** @var ReflectionNamedType $reflectionType */
                    $reflectionType = $property->getType();
                    $type = $reflectionType->getName();
                    if ($type == ArrayClass::class) {
                        $relationship = new RelationshipDescription();
                        $relationship->name = $name;
                        $relationship->entity = $entityDescription;
                        $relationship->isToMany = true;
                        $relationship->lazyInverseRelationshipName = "transaction";
                        $relationship->lazyDestinationEntityName = "PersistentHistoryChange";
                        return $relationship;
                    }
                    $attribute = new AttributeDescription();
                    $attribute->name = $name;
                    $attribute->entity = $entityDescription;
                    if ($type == "string") {
                        $attribute->type = AttributeType::string;
                    } elseif ($type == "int") {
                        $attribute->type = AttributeType::integer64;
                        $attribute->isOptional = false;
                    } elseif ($type == Date::class) {
                        $attribute->type = AttributeType::date;
                        $attribute->isOptional = false;
                    } elseif ($type == PersistentHistoryToken::class) {
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
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $entityDescription->properties = /** @phpstan-ignore-line */
                (new ArrayClass($reflectionClass->getProperties()))->compactMap(function (ReflectionProperty $property) use ($entityDescription): ?PropertyDescription {
                    if ($property->isStatic()) {
                        return null;
                    }
                    $name = $property->name;
                    /** @var ReflectionNamedType $reflectionType */
                    $reflectionType = $property->getType();
                    $type = $reflectionType->getName();
                    if ($type == PersistentHistoryTransaction::class) {
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
                    if ($type == "string") {
                        $attribute->type = AttributeType::string;
                    } elseif ($type == "int") {
                        $attribute->type = AttributeType::integer16;
                        $attribute->isOptional = false;
                    } elseif ($type == Date::class) {
                        $attribute->type = AttributeType::date;
                        $attribute->isOptional = false;
                    } elseif ($type == ManagedObjectID::class) {
                        $attribute->type = AttributeType::objectID;
                        $attribute->isOptional = false;
                    } elseif ($type == Dictionary::class || $type == Set::class) {
                        $attribute->type = AttributeType::transformable;
                    } elseif ($type == PersistentHistoryChangeType::class) {
                        $attribute->type = AttributeType::integer16;
                        $attribute->isOptional = false;
                    }
                    return $attribute;
                });
            $managedObjectModel->addEntity($entityDescription);
            PersistentHistoryChange::$entityDescription = $entityDescription;
        }
    }

    public function __get(string $name)
    {
        if ($name == "adapter") {
            $this->$name = new SQLAdapter($this);
            return $this->$name;
        } elseif ($name == "schemaValidationConnection") {
            $this->$name = new SQLConnection($this->adapter);
            return $this->$name;
        } elseif ($name == "queryGenerationTrackingConnection") {
            $this->$name = new SQLConnection($this->adapter);
            return $this->$name;
        } elseif ($name == "maxPrimaryKeys") {
            $this->$name = new Dictionary();
            return $this->$name;
        } elseif ($name == "model") {
            $this->$name = new SQLModel($this->persistentStoreCoordinator->managedObjectModel, $this->configurationName);
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }

    public static function migrationManagerClass(): string
    {
        return SQLInPlaceMigrationManager::class;
    }

    /**
     * @throws Exception
     */
    public static function cachedModelForPersistentStoreWithURL(URL $url, ?Dictionary $options = null): ?ManagedObjectModel
    {
        $connection = new SQLConnection();
        if ($connection->hasMetadataTable()) {
            return $connection->fetchCachedModel();
        }
        return null;
    }

    public static function destroyPersistentStoreAtURL(URL $url, ?Dictionary $options = null): bool
    {
        return SQLConnection::destroyPersistentStoreAtURL($url, $options);
    }

    public static function replacePersistentStoreAtURL(URL $destinationURL, ?Dictionary $destinationOptions, URL $sourceURL, ?Dictionary $sourceOptions): bool
    {
        return SQLConnection::replacePersistentStoreAtURL($destinationURL, $destinationOptions, $sourceURL, $sourceOptions);
    }

    public static function metadataForPersistentStore(URL $url): Dictionary
    {
        $connection = new SQLConnection();
        if ($connection->hasMetadataTable() && ($metadata = $connection->fetchMetadata())) {
            return $metadata;
        }
        return new Dictionary([StoreTypeKey => SQLStoreType]);
    }

    public static function setMetadata(?Dictionary $metadata, URL $url): bool
    {
        $metadata ??= new Dictionary([StoreTypeKey => SQLStoreType, StoreUUIDKey => (new UUID())->uuidString]);
        $connection = new SQLConnection();
        $connection->saveMetadata($metadata);
        return true;
    }

    public function loadMetadata(): bool
    {
        if ($this->queryGenerationTrackingConnection->hasMetadataTable() && ($metadata = $this->queryGenerationTrackingConnection->fetchMetadata())) {
            $this->metadata = $metadata;
            $this->identifier = $metadata[StoreUUIDKey];
        }
        return true;
    }

    public function willRemove(PersistentStoreCoordinator $coordinator): void
    {
    }

    /**
     * @param ArrayClass<SQLEntity> $entities
     * @throws Exception
     */
    private function recomputePrimaryKeyMaxForEntities(ArrayClass $entities): void
    {
        if (!$entities->isEmpty()) {
            $entities->appendContentsOf($entities->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->entitySpecificRelationships->filter(fn(SQLRelationship $relationship): bool => $relationship->relationshipDescription->deleteRule == DeleteRule::cascadeDeleteRule))->map(fn(SQLRelationship $relationship): SQLEntity => $relationship->destinationEntity));
            /** @var ArrayClass<SQLEntity> $entities */
            $entities = new ArrayClass(new Set($entities->compactMap(fn(SQLEntity $entity): ?SQLEntity => $entity->isRootEntity ? $entity : $entity->rootEntity)));
            $statement = SQLStatement::merging($entities->map(fn(SQLEntity $entity): SQLStatement => new SQLStatement("ALTER TABLE `$entity->tableName` AUTO_INCREMENT = 0")));
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
        if ($requestContext->isWritingRequest && $this->isReadOnly) {
            throw new InternalInconsistencyException("Cannot modify a read only persistent store");
        }
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        if ($requestContext->isWritingRequest) {
            if (!$requestContext->hasHistoryTracking && $this->options?->valueForKey(PersistentStoreRemoteChangeNotificationPostOptionKey) && $requestContext->transactionID->intValue) {
                $this->postChangeNotificationWithTransactionID($requestContext->transactionID);
            }
            if ($requestContext instanceof SQLBatchDeleteRequestContext) {
                /** @var ArrayClass<bool|int|ManagedObjectID> $result */
                $result = $requestContext->result;
                if (match ($requestContext->request->resultType) {
                    BatchDeleteRequestResultType::statusOnly => !$result->containsElement(false),
                    BatchDeleteRequestResultType::objectIDs => !$result->isEmpty(),
                    BatchDeleteRequestResultType::count => (bool)$result->sum()
                }) {
                    /** @var SQLEntity $entity */
                    $entity = $this->model->entitiesByName[$requestContext->fetchRequestForObjectsToDelete->entity->name];
                    $this->recomputePrimaryKeyMaxForEntities(new ArrayClass([$entity]));
                }
            } elseif ($requestContext instanceof SQLSaveChangesRequestContext) {
                if (($deletedObjects = $requestContext->request->deletedObjects) && !$deletedObjects->isEmpty()) {
                    $this->recomputePrimaryKeyMaxForEntities(new ArrayClass($deletedObjects->compactMap(fn(ManagedObject $object): ?SQLEntity => $this->model->entitiesByName[$object->entity->name])));
                }
            }
        }
        return $requestContext->result;
    }

    /**
     * @throws Exception
     */
    private function processFetchRequest(FetchRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return $this->processRequestContext(new SQLFetchRequestContext($request, $context, $this));
    }

    /**
     * @throws Exception
     */
    private function processRefreshObjects(RefreshRequest $request, ManagedObjectContext $context): ArrayClass
    {
        return new ArrayClass($request->refreshObjects->compactMap(fn(ManagedObject $object): ?Dictionary => $this->processRequestContext(new SQLObjectFaultRequestContext($object->objectID, $context, $this))));
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

    public function execute(PersistentStoreRequest $request, ManagedObjectContext $context): ArrayClass
    {
        if ($request instanceof FetchRequest) {
            return $this->processFetchRequest($request, $context);
        } elseif ($request instanceof RefreshRequest) {
            return $this->processRefreshObjects($request, $context);
        } elseif ($request instanceof SaveChangesRequest) {
            return $this->processSaveChanges($request, $context);
        } elseif ($request instanceof BatchInsertRequest) {
            return $this->processBatchInsert($request, $context);
        } elseif ($request instanceof BatchUpdateRequest) {
            return $this->processBatchUpdate($request, $context);
        } elseif ($request instanceof BatchDeleteRequest) {
            return $this->processBatchDelete($request, $context);
        } elseif ($request instanceof PersistentHistoryChangeRequest) {
            return $this->processChangeRequest($request, $context);
        }
        return new ArrayClass();
    }

    /**
     * @throws Exception
     */
    public function newObjectIDSetsForToManyPrefetchingRequest(FetchRequest $request, ArrayClass $sourceObjectIDs, string $orderColumnName, ManagedObjectContext $context): mixed
    {
        $requestContext = new SQLObjectIDSetFetchRequestContext($request, $context, $this, $sourceObjectIDs, $orderColumnName);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        return $requestContext->result;
    }

    public function newValueForRelationship(RelationshipDescription $relationship, ManagedObjectID $objectID, ManagedObjectContext $context): mixed
    {
        $requestContext = new SQLRelationshipFaultRequestContext($objectID, $relationship, $context, $this);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        return $requestContext->result;
    }

    public function newValuesForObjectWithID(ManagedObjectID $objectID, ManagedObjectContext $context): ?IncrementalStoreNode
    {
        $requestContext = new SQLObjectFaultRequestContext($objectID, $context, $this);
        $requestContext->executeRequestUsingConnection($this->queryGenerationTrackingConnection);
        $values = $requestContext->result;
        if (!$values instanceof Dictionary) {
            return null;
        }
        return new IncrementalStoreNode($objectID, $values);
    }

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

    public function load(): bool
    {
        return $this->queryGenerationTrackingConnection->connect();
    }

    public function ensureDatabaseMatchesModel(): void
    {
    }
}
