<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 14:09
 */

namespace Sabatier\CoreData;

use BackedEnum;
use Exception;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\ProcessInfo;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\absolute_time_get_current;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_time;
use function Sabatier\Foundation\pluralize;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/** @internal */
final class SQLConnection extends ObjectClass
{
    private(set) SQLSchema $schema {
        get => $this->schema ??= SQLSchema::schema($this->sqlCore?->url?->host);
    }
    public ?SQLCore $sqlCore {
        get => $this->adapter?->sqlCore;
    }
    private SQLModel $model {
        get => $this->model ??= $this->sqlCore?->model ?? fatal_error("invalid argument: model cannot be null");
    }
    /** @var ArrayClass<SQLEntity> */
    private ArrayClass $rootSchemaEntities {
        get => $this->rootSchemaEntities ??= $this->model->entities->filter(fn(SQLEntity $entity): bool => $entity->isRootEntity && !$entity->entityDescription->isPersistentHistoryEntity);
    }
    /** @var ArrayClass<SQLEntity> */
    private ArrayClass $persistentHistoryEntities {
        get => $this->persistentHistoryEntities ??= $this->model->entities->filter(fn(SQLEntity $entity): bool => $entity->entityDescription->isPersistentHistoryEntity);
    }
    /** @var ArrayClass<string> */
    private ArrayClass $rootTableNames {
        get => $this->rootTableNames ??= $this->rootSchemaEntities->map(fn(SQLEntity $entity): string => $entity->tableName);
    }
    /** @var Set<string> */
    private Set $correlationTableNames {
        get => $this->correlationTableNames ??= new Set($this->rootSchemaEntities->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->manyToManyRelationships)->map(fn(SQLManyToMany $manyToMany): string => $manyToMany->correlationTableName));
    }
    /** @var Set<string> */
    private Set $allSchemaTableNames {
        get {
            if (!isset($this->allSchemaTableNames)) {
                $allSchemaTableNames = new Set($this->rootTableNames);
                $allSchemaTableNames->formUnion($this->correlationTableNames);
                if ($this->hasPersistentHistoryTables) {
                    $allSchemaTableNames->formUnion($this->persistentHistoryEntities->map(fn(SQLEntity $entity): string => $entity->tableName));
                }
                $this->allSchemaTableNames = $allSchemaTableNames;
            }
            return $this->allSchemaTableNames;
        }
    }
    private(set) bool $hasMetadataTable {
        /**
         * @throws Exception
         */
        get => $this->hasMetadataTable ??= (bool)$this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", new ArrayClass([$this->schema->name, "PersistentStoreMetadata"])))->fetchColumn();
    }
    private(set) bool $hasCachedModelTable {
        /**
         * @throws Exception
         */
        get => $this->hasCachedModelTable ??= (bool)$this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", new ArrayClass([$this->schema->name, "ManagedObjectModel"])))->fetchColumn();
    }
    private(set) bool $hasPersistentHistoryTables {
        /**
         * @throws Exception
         */
        get => $this->hasPersistentHistoryTables ??= (bool)$this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name IN (?, ?)", new ArrayClass([$this->schema->name, "PersistentHistoryTransaction", "PersistentHistoryChange"])))->fetchColumn();
    }
    private(set) ?ManagedObjectModel $cachedModel {
        /**
         * @throws Exception
         */
        get {
            if (!isset($this->cachedModel)) {
                $this->connect();
                $this->createCachedModelTable();
                if ($array = $this->execute(new SQLStatement("SELECT * FROM `ManagedObjectModel`"))->fetch()) {
                    $this->cachedModel = $this->decompressedModelWithData($array["data"]);
                }
                $this->cachedModel ??= null;
            }
            return $this->cachedModel;
        }
    }
    private SQLStoreRequestContext $requestContext;
    public string $bundleID {
        get => Bundle::main()->bundleIdentifier ?? ProcessInfo::processInfo()->globallyUniqueString;
    }
    private ?Mysql $mysql = null;
    private(set) bool $isOpen = false;

    public function __construct(public readonly ?SQLAdapter $adapter = null)
    {
    }

    public function __destruct()
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->disconnect();
    }

    /**
     * @throws Exception
     */
    public static function destroyPersistentStoreAtURL(URL $url, ?Dictionary $options = null): bool
    {
        !$options?->valueForKey(ReadOnlyPersistentStoreOption) ?: fatal_error("Cannot destroy a read only persistent store");
        $connection = new SQLConnection();
        $connection->schema = SQLSchema::schema($url->host);
        return $connection->destroySchema();
    }

    /**
     * @param URL $destinationURL
     * @param Dictionary<mixed>|null $destinationOptions
     * @param URL $sourceURL
     * @param Dictionary<mixed>|null $sourceOptions
     * @return bool
     * @throws Exception
     */
    public static function replacePersistentStoreAtURL(URL $destinationURL, ?Dictionary $destinationOptions, URL $sourceURL, ?Dictionary $sourceOptions): bool
    {
        $destinationDatabaseName = $destinationURL->host ?? fatal_error("Cannot replace a persistent store without a destination database name");
        $sourceDatabaseName = $sourceURL->host ?? fatal_error("Cannot replace a persistent store without a source database name");
        $destinationDatabaseName !== $sourceDatabaseName ?: fatal_error("Cannot replace a persistent store with itself");
        $destinationURL->scheme === "sql" ?: fatal_error("Cannot replace a persistent store with a non sql destination");
        !$destinationOptions?->valueForKey(ReadOnlyPersistentStoreOption) ?: fatal_error("Cannot replace a read only persistent store");
        $sourceURL->scheme === "sql" ?: fatal_error("Cannot replace a persistent store with a non sql source");
        $modelURL = $destinationOptions?->valueForKey(ManagedObjectModelURLOption) ?? fatal_error("Cannot replace a persistent store without a model URL");
        $managedObjectModel = new ManagedObjectModel($modelURL);
        $coordinator = new PersistentStoreCoordinator($managedObjectModel);
        $core = new SQLCore($coordinator, $destinationDatabaseName, $destinationURL, $destinationOptions);
        $connection = $core->schemaValidationConnection;
        $tableNames = $connection->allSchemaTableNames;
        $connection->dropDatabase($destinationDatabaseName);
        $connection->createDatabase($destinationDatabaseName);
        $connection->moveTables($sourceDatabaseName, $destinationDatabaseName, $tableNames);
        $connection->useDatabase($destinationDatabaseName);
        $connection->saveCachedModel($connection->model);
        return self::destroyPersistentStoreAtURL($sourceURL, $sourceOptions);
    }

    private function mysql(): Mysql
    {
        if ($this->mysql === null) {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, Mysql::ATTR_USE_BUFFERED_QUERY => true];
            if ($timeout = $this->sqlCore?->options?->valueForKey(PersistentStoreTimeoutOption)) {
                $options[PDO::ATTR_TIMEOUT] = $timeout;
            }
            $this->mysql = Mysql::connect("mysql:host={$this->schema->host};charset={$this->schema->charset};unix_socket={$this->schema->socket};", $this->schema->credential->user, $this->schema->credential->password, $options);
        }
        return $this->mysql;
    }

    /**
     * @throws Exception
     */
    public function connect(): bool
    {
        if ($this->isOpen) {
            return true;
        }
        $this->isOpen = true;
        $schemaName = $this->schema->name;
        if (SQLCore::$debugLevel->value) {
            error_log(sprintf("CoreData: annotation: Connecting to %s database \"%s\"", SQLStoreType, $schemaName));
        }
        if ($this->createSchemaIfNeeded()) {
            return true;
        }
        $this->mysql()->exec("USE `$schemaName`");
        return true;
    }

    /**
     * @throws Exception
     */
    public function disconnect(): bool
    {
        if (!$this->isOpen) {
            return true;
        }
        if (SQLCore::$debugLevel->value) {
            error_log("CoreData: annotation: Disconnecting from sql database \"{$this->schema->name}\"");
        }
        $this->mysql = null;
        $this->isOpen = false;
        return true;
    }

    /**
     * @throws Exception
     */
    public function execute(SQLStatement $statement): PDOStatement
    {
        $time = absolute_time_get_current();
        if (SQLCore::$debugLevel->value) {
            error_log(sprintf("CoreData: sql: \n%s", $statement->formatted(SQLStatementFormatterStyle::defaultFormatterStyle())));
        }
        $mysql = $this->mysql();
        if ($statement->arguments->isEmpty) {
            $pdoStatement = $mysql->query($statement->string);
            if (SQLCore::$debugLevel->value) {
                error_log(sprintf("CoreData: annotation: execution time: %s for %d %s", human_readable_time(absolute_time_get_current() - $time), $pdoStatement->rowCount(), pluralize("row", $pdoStatement->rowCount())));
            }
            return $pdoStatement;
        }
        $pdoStatement = $mysql->prepare($statement->string);
        $pdoStatement->execute($statement->arguments->map(function (mixed $e): mixed {
            if ($e instanceof Nil || $e instanceof BackedEnum) {
                return $e->value;
            }
            if ($e instanceof ManagedObjectID) {
                return $e->referenceObject;
            }
            if ($e instanceof Number) {
                if (is_bool($e->value)) {
                    return $e->intValue;
                }
                return $e->value;
            }
            if (is_bool($e)) {
                return (int)$e;
            }
            return $e;
        })->array);
        if (SQLCore::$debugLevel->value) {
            error_log(sprintf("CoreData: annotation: execution time: %s for %d %s", human_readable_time(absolute_time_get_current() - $time), $pdoStatement->rowCount(), pluralize("row", $pdoStatement->rowCount())));
        }
        return $pdoStatement;
    }

    /**
     * @throws Exception
     */
    private function tableHasRows(/** @noinspection PhpSameParameterValueInspection */ string $tableName): bool
    {
        return (bool)$this->countOfRowsInTable($tableName);
    }

    /**
     * @throws Exception
     */
    private function countOfRowsInTable(string $tableName): int
    {
        return (int)$this->execute(new SQLStatement("SELECT COUNT(*) FROM `$tableName`"))->fetchColumn();
    }

    /**
     * @throws Exception
     */
    private function insertBatchDeleteChangesForTransactionID(int $transactionID): void
    {
        /** @var SQLBatchDeleteRequestContext $requestContext */
        $requestContext = $this->requestContext;
        $statement = new SQLStatement("INSERT INTO `PersistentHistoryTransaction` (`transactionID`, `author`, `bundleID`, `contextName`, `processID`, `storeID`) VALUES (?, ?, ?, ?, ?, ?)", new ArrayClass([$transactionID, $requestContext->context->transactionAuthor, $this->bundleID, $requestContext->context->name, ProcessInfo::processInfo()->globallyUniqueString, $requestContext->sqlCore->identifier]));
        $this->execute($statement);
        $valueTransformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName);
        $statement = SQLStatement::merging($requestContext->affectedObjectIDs->map(fn(ManagedObjectID $objectID): SQLStatement => new SQLStatement("INSERT INTO `PersistentHistoryChange` (`changedObjectID`, `changeType`, `transactionID`) VALUES (?, ?, ?)", new ArrayClass([$valueTransformer?->transformedValue($objectID), PersistentHistoryChangeType::delete, $transactionID]))));
        $this->execute($statement);
    }

    /**
     * @throws Exception
     */
    private function insertUpdates(ArrayClass $updatedObjectIDs, int $transactionID, Set $updatedAttributes): void
    {
        /** @var SQLBatchUpdateRequestContext $requestContext */
        $requestContext = $this->requestContext;
        $statement = new SQLStatement("INSERT INTO `PersistentHistoryTransaction` (`transactionID`, `author`, `bundleID`, `contextName`, `processID`, `storeID`) VALUES (?, ?, ?, ?, ?, ?)", new ArrayClass([$transactionID, $requestContext->context->transactionAuthor, $this->bundleID, $requestContext->context->name, ProcessInfo::processInfo()->globallyUniqueString, $requestContext->sqlCore->identifier]));
        $this->execute($statement);
        $valueTransformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName);
        $statement = SQLStatement::merging($updatedObjectIDs->map(fn(ManagedObjectID $objectID): SQLStatement => new SQLStatement("INSERT INTO `PersistentHistoryChange` (`changedObjectID`, `changeType`, `updatedProperties`, `transactionID`) VALUES (?, ?, ?, ?)", new ArrayClass([$valueTransformer?->transformedValue($objectID), PersistentHistoryChangeType::update, $valueTransformer?->transformedValue($updatedAttributes), $transactionID]))));
        $this->execute($statement);
    }

    /**
     * @throws Exception
     */
    private function insertBatchInserts(ArrayClass $insertedObjectIDs, int $transactionID): void
    {
        /** @var SQLBatchInsertRequestContext $requestContext */
        $requestContext = $this->requestContext;
        $statement = new SQLStatement("INSERT INTO `PersistentHistoryTransaction` (`transactionID`, `author`, `bundleID`, `contextName`, `processID`, `storeID`) VALUES (?, ?, ?, ?, ?, ?)", new ArrayClass([$transactionID, $requestContext->context->transactionAuthor, $this->bundleID, $requestContext->context->name, ProcessInfo::processInfo()->globallyUniqueString, $requestContext->sqlCore->identifier]));
        $this->execute($statement);
        $statement = SQLStatement::merging($insertedObjectIDs->map(fn(ManagedObjectID $objectID): SQLStatement => new SQLStatement("INSERT INTO `PersistentHistoryChange` (`changedObjectID`, `changeType`, `transactionID`) VALUES (?, ?, ?)", new ArrayClass([ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName)?->transformedValue($objectID), PersistentHistoryChangeType::insert, $transactionID]))));
        $this->execute($statement);
    }

    /**
     * @param Set<ManagedObjectID> $changedObjectIDs
     * @param PersistentHistoryChangeType $type
     * @param int $transactionID
     * @param ManagedObjectContext $context
     * @throws Exception
     */
    private function insertChanges(Set $changedObjectIDs, PersistentHistoryChangeType $type, int $transactionID, ManagedObjectContext $context): void
    {
        $statement = SQLStatement::merging(new ArrayClass($changedObjectIDs->map(function (ManagedObjectID $changedObjectID) use ($type, $transactionID, $context): SQLStatement {
            $tombstone = null;
            $updatedProperties = null;
            $valueTransformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName);
            $managedObject = $context->object($changedObjectID);
            if ($type === PersistentHistoryChangeType::update) {
                $updatedProperties = new Set($managedObject->changedValuesForCurrentEvent()->compactMap(fn(mixed $value, string $key): ?string => $managedObject->entity->propertiesByName->valueForKey($key)?->name));
            } elseif ($type === PersistentHistoryChangeType::delete) {
                $attributesByName = $managedObject->entity->attributesByName->filter(fn(AttributeDescription $attribute): bool => $attribute->preservesValueInHistoryOnDeletion);
                if (!$attributesByName->isEmpty) {
                    $dictionary = $managedObject->dictionaryWithValues($attributesByName->map(fn(AttributeDescription $attribute): string => $attribute->name));
                    if (!$dictionary->isEmpty) {
                        $tombstone = $dictionary;
                    }
                }
            }
            return new SQLStatement("INSERT INTO `PersistentHistoryChange` (`changedObjectID`, `changeType`, `tombstone`, `updatedProperties`, `transactionID`) VALUES (?, ?, ?, ?, ?)", new ArrayClass([$valueTransformer?->transformedValue($changedObjectID), $type, $valueTransformer?->transformedValue($tombstone), $valueTransformer?->transformedValue($updatedProperties), $transactionID]));
        })));
        $this->execute($statement);
    }

    /**
     * @throws Exception
     */
    public function insertTransactionForRequestContext(SQLStoreRequestContext $requestContext): int
    {
        $this->requestContext = $requestContext;
        if ($requestContext instanceof SQLSaveChangesRequestContext) {
            if (!$requestContext->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey)) {
                return 0;
            }
            if ($requestContext->hasHistoryTracking) {
                return 0;
            }
            /** @var Set<ManagedObject> $insertedObjects */
            $insertedObjects = $requestContext->request->insertedObjects ?? new Set();
            /** @var Set<ManagedObject> $updatedObjects */
            $updatedObjects = $requestContext->request->updatedObjects ?? new Set();
            /** @var Set<ManagedObject> $deletedObjects */
            $deletedObjects = $requestContext->request->deletedObjects ?? new Set();
            if ($insertedObjects->isEmpty && $updatedObjects->isEmpty && $deletedObjects->isEmpty) {
                return 0;
            }
            $this->createHistoryTrackingTables();
            $transactionID = $this->fetchMaxPrimaryKey("PersistentHistoryTransaction") + 1;
            $statement = new SQLStatement("INSERT INTO `PersistentHistoryTransaction` (`transactionID`, `author`, `bundleID`, `contextName`, `processID`, `storeID`) VALUES (?, ?, ?, ?, ?, ?)", new ArrayClass([$transactionID, $requestContext->context->transactionAuthor, $this->bundleID, $requestContext->context->name, ProcessInfo::processInfo()->globallyUniqueString, $requestContext->sqlCore->identifier]));
            $this->execute($statement);
            if (!$insertedObjects->isEmpty) {
                $this->insertChanges($insertedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID), PersistentHistoryChangeType::insert, $transactionID, $requestContext->context);
            }
            if (!$updatedObjects->isEmpty) {
                $this->insertChanges($updatedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID), PersistentHistoryChangeType::update, $transactionID, $requestContext->context);
            }
            if (!$deletedObjects->isEmpty) {
                $this->insertChanges($deletedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID), PersistentHistoryChangeType::delete, $transactionID, $requestContext->context);
            }
            return $transactionID;
        }
        assert($requestContext instanceof SQLBatchOperationRequestContext);
        if (!$requestContext->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey)) {
            return 0;
        }
        $affectedObjectIDs = $requestContext->affectedObjectIDs;
        if ($affectedObjectIDs->isEmpty) {
            return 0;
        }
        $this->createHistoryTrackingTables();
        $transactionID = $this->fetchMaxPrimaryKey("PersistentHistoryTransaction") + 1;
        if ($requestContext instanceof SQLBatchInsertRequestContext) {
            $this->insertBatchInserts($affectedObjectIDs, $transactionID);
        }
        if ($requestContext instanceof SQLBatchUpdateRequestContext) {
            $this->insertUpdates($affectedObjectIDs, $transactionID, new Set($requestContext->request->propertiesToUpdate?->keys ?? []));
        }
        if ($requestContext instanceof SQLBatchDeleteRequestContext) {
            $this->insertBatchDeleteChangesForTransactionID($transactionID);
        }
        return $transactionID;
    }

    /**
     * @throws Exception
     */
    public function hasHistoryRows(): bool
    {
        return $this->hasPersistentHistoryTables && $this->tableHasRows("PersistentHistoryTransaction");
    }

    /**
     * @throws Exception
     */
    public function dropHistoryBeforeTransactionID(int $transactionID): void
    {
        if ($this->hasPersistentHistoryTables) {
            $this->execute(new SQLStatement("DELETE FROM `PersistentHistoryTransaction` WHERE `transactionID` < ?", new ArrayClass([$transactionID])));
        }
    }

    /**
     * @throws Exception
     */
    public function hasHistoryTransactionWithNumber(Number $transactionNumber): bool
    {
        return $transactionNumber->boolValue && $this->hasPersistentHistoryTables && $this->execute(new SQLStatement("SELECT COUNT(`transactionID`) FROM `PersistentHistoryTransaction` WHERE `transactionID` = ?", new ArrayClass([$transactionNumber])))->fetchColumn();
    }

    /**
     * @throws Exception
     */
    private function createHistoryTrackingTables(): void
    {
        if (!$this->hasPersistentHistoryTables) {
            $entities = $this->persistentHistoryEntities;
            $this->createEntityTables($entities);
            $this->applyConstraints($entities);
            $this->hasPersistentHistoryTables = true;
        }
    }

    /**
     * @throws Exception
     */
    public function dropHistoryTrackingTables(): void
    {
        if (!$this->hasPersistentHistoryTables) {
            return;
        }
        $entities = $this->persistentHistoryEntities;
        foreach ($entities as $entity) {
            if ($statement = $this->adapter?->newDropIndexesStatement($entity)) {
                $this->execute($statement);
            }
        }
        foreach ($entities as $entity) {
            if ($statement = $this->adapter?->newDropTableStatement($entity)) {
                $this->execute($statement);
            }
        }
        $this->hasPersistentHistoryTables = false;
    }

    /**
     * @throws Exception
     */
    public function writeCorrelationChangesFromTracker(SQLCorrelationTableUpdateTracker $tracker): void
    {
        if (($inserts = $tracker->inserts) && ($statement = $this->adapter?->newCorrelationInsertStatementForRelationship($tracker->relationship, new ArrayClass($inserts)))) {
            $this->execute($statement);
        }
        if (($deletes = $tracker->deletes) && ($statement = $this->adapter?->newCorrelationDeleteStatementForRelationship($tracker->relationship, new ArrayClass($deletes)))) {
            $this->execute($statement);
        }
        if (($reorders = $tracker->reorders) && ($statement = $this->adapter?->newCorrelationReorderStatementForRelationship($tracker->relationship, new ArrayClass($reorders)))) {
            $this->execute($statement);
        }
    }

    public function lastInsertRowID(): int
    {
        return (int)$this->mysql()->lastInsertId();
    }

    /**
     * @throws Exception
     */
    public function fetchMaxPrimaryKey(string $entityName): int
    {
        /** @var SQLEntity $entity */
        $entity = $this->model->entitiesByName[$entityName] ?? fatal_error("Invalid argument: entity \"$entityName\" does not exists");
        return (int)$this->execute(new SQLStatement("SELECT MAX({$entity->primaryKey->columnName}) FROM `$entity->tableName`"))->fetchColumn();
    }

    /**
     * @throws Exception
     */
    public function saveCachedModel(SQLModel $model): void
    {
        $this->createCachedModelTable();
        $this->execute(new SQLStatement("INSERT INTO `ManagedObjectModel` (`modelID`, `data`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `modelID` = VALUES(`modelID`), `data` = VALUES(`data`)", new ArrayClass([1, $this->compressedDataWithModel($model->managedObjectModel)])));
        /** @var Dictionary<mixed> $metadata */
        $metadata = $this->adapter?->sqlCore?->metadata ?? new Dictionary([StoreTypeKey => SQLStoreType]);
        $metadata[StoreModelVersionHashesKey] = $model->managedObjectModel->versionHash;
        $this->saveMetadata($metadata);
    }

    /**
     * @throws Exception
     */
    private function compressedDataWithModel(ManagedObjectModel $model): string
    {
        return KeyedArchiver::archivedData($model->jsonSerialize());
    }

    /**
     * @throws Exception
     */
    private function decompressedModelWithData(string $compressedData): ManagedObjectModel
    {
        return ManagedObjectModel::newModel($compressedData);
    }

    /**
     * @throws Exception
     */
    private function createCachedModelTable(): void
    {
        if (!$this->hasCachedModelTable) {
            $this->execute(new SQLStatement("CREATE TABLE IF NOT EXISTS `ManagedObjectModel` (`modelID` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT, `data` LONGBLOB NOT NULL, PRIMARY KEY (`modelID`)) ENGINE={$this->schema->engine} DEFAULT CHARSET={$this->schema->charset} COLLATE={$this->schema->collation}"));
            $this->hasCachedModelTable = true;
        }
    }

    /**
     * @throws Exception
     */
    private function createMetadata(): void
    {
        if (!$this->hasMetadataTable) {
            $this->execute(new SQLStatement("CREATE TABLE IF NOT EXISTS `PersistentStoreMetadata` (`metadataID` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT, `data` LONGBLOB NOT NULL, PRIMARY KEY (`metadataID`)) ENGINE={$this->schema->engine} DEFAULT CHARSET={$this->schema->charset} COLLATE={$this->schema->collation}"));
            $this->hasMetadataTable = true;
        }
    }

    /**
     * @throws Exception
     */
    private function decompressedMetadataWithData(string $compressedData): ?Dictionary
    {
        return KeyedUnarchiver::unarchiveTopLevelObjectWithData($compressedData);
    }

    /**
     * @throws Exception
     */
    public function fetchMetadata(): ?Dictionary
    {
        $this->connect();
        $this->createMetadata();
        if ($array = $this->execute(new SQLStatement("SELECT * FROM `PersistentStoreMetadata`"))->fetch()) {
            return $this->decompressedMetadataWithData($array["data"]);
        }
        return null;
    }

    /**
     * @throws Exception
     */
    private function compressedDataWithMetadata(Dictionary $metadata): string
    {
        return KeyedArchiver::archivedData($metadata);
    }

    /**
     * @throws Exception
     */
    public function saveMetadata(Dictionary $metadata): void
    {
        $this->connect();
        $this->createMetadata();
        $this->execute(new SQLStatement("INSERT INTO `PersistentStoreMetadata` (`metadataID`, `data`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `metadataID` = VALUES(`metadataID`), `data` = VALUES(`data`)", new ArrayClass([1, $this->compressedDataWithMetadata($metadata)])));
    }

    /**
     * @throws Exception
     */
    public function createSchema(): bool
    {
        $time = absolute_time_get_current();
        $database = $this->schema->name;
        $model = $this->model;
        $entities = $this->rootSchemaEntities;
        if (SQLCore::$debugLevel->value) {
            error_log("CoreData: annotation: creating database \"$database\"");
        }
        $this->createDatabase($database);
        $this->useDatabase($database);
        $this->createEntityTables($entities);
        $this->applyConstraints($entities);
        $this->createPivotTables($entities);
        $this->saveCachedModel($model);
        if (SQLCore::$debugLevel->value) {
            error_log("CoreData: annotation: database \"$database\" created, total execution time: " . human_readable_time(absolute_time_get_current() - $time));
        }
        return true;
    }

    /**
     * @throws Exception
     */
    private function createDatabase(string $database): void
    {
        if ($statement = $this->adapter?->newCreateDatabaseStatement($database)) {
            $this->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    private function useDatabase(string $database): void
    {
        if ($statement = $this->adapter?->newUseDatabaseStatement($database)) {
            $this->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    private function dropDatabase(string $database): void
    {
        if ($statement = $this->adapter?->newDropDatabaseStatement($database)) {
            $this->execute($statement);
        }
    }

    /**
     * @param ArrayClass<SQLEntity> $entities
     * @throws Exception
     */
    private function createEntityTables(ArrayClass $entities): void
    {
        foreach ($entities as $entity) {
            $this->createTableForEntity($entity);
        }
    }

    /**
     * @param ArrayClass<SQLEntity> $entities
     * @throws Exception
     */
    public function applyConstraints(ArrayClass $entities): void
    {

        foreach ($entities as $entity) {
            $this->createIndexesForEntity($entity);
        }
    }

    /**
     * @throws Exception
     */
    private function createTableForEntity(SQLEntity $entity): void
    {
        if ($statement = $this->adapter?->newCreateTableStatement($entity)) {
            $this->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    private function createIndexesForEntity(SQLEntity $entity): void
    {
        if ($statement = $this->adapter?->newCreateIndexesStatement($entity)) {
            $this->execute($statement);
        }
    }

    /**
     * @param ArrayClass<SQLEntity> $entities
     * @throws Exception
     */
    private function createPivotTables(ArrayClass $entities): void
    {
        $adapter = $this->adapter ?? fatal_error();
        $manyToManyRelationships = new Set($entities)->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->manyToManyRelationships);
        /** @var Set<SQLStatement> $statements */
        $statements = $manyToManyRelationships->map($adapter->newCreateTableStatementForManyToMany(...));
        if (!$statements->isEmpty) {
            $this->execute(SQLStatement::merging(new ArrayClass($statements)));
        }
        /** @var Set<SQLStatement> $statements */
        $statements = $manyToManyRelationships->map($adapter->newCreateIndexesStatementForManyToMany(...));
        if (!$statements->isEmpty) {
            $this->execute(SQLStatement::merging(new ArrayClass($statements)));
        }
    }

    /**
     * @param string $sourceDatabaseName
     * @param string $destinationDatabaseName
     * @param Set<string> $tableNames
     * @throws Exception
     */
    private function moveTables(string $sourceDatabaseName, string $destinationDatabaseName, Set $tableNames): void
    {
        if (!$tableNames->isEmpty && ($statement = $this->adapter?->newRenameTablesStatement($sourceDatabaseName, $destinationDatabaseName, $tableNames))) {
            $this->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    public function hasSchema(): bool
    {
        /** @noinspection SqlShadowingAlias */
        return (bool)$this->execute(new SQLStatement("SELECT COUNT(*) schema_name FROM information_schema.schemata WHERE schema_name = ?", new ArrayClass([$this->schema->name])))->fetchColumn();
    }

    /**
     * @throws Exception
     */
    public function createSchemaIfNeeded(): bool
    {
        return !$this->hasSchema() && $this->createSchema();
    }

    /**
     * @throws Exception
     */
    public function destroySchema(): bool
    {
        $this->execute(new SQLStatement("DROP DATABASE IF EXISTS `{$this->schema->name}`"));
        return true;
    }
}
