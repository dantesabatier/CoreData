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
use function Sabatier\Foundation\debuglog;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_time;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/** @internal */
class SQLConnection extends ObjectClass
{
    private(set) SQLSchema $schema {
        get => $this->schema ??= SQLSchema::schema($this->sqlCore?->url?->host);
    }
    public ?SQLCore $sqlCore {
        get => $this->adapter?->sqlCore;
    }
    private(set) bool $hasMetadataTable {
        get => $this->hasMetadataTable ??= (bool)$this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", new ArrayClass([$this->schema->name, "PersistentStoreMetadata"])))->fetchColumn();
    }
    private(set) bool $hasCachedModelTable {
        get => $this->hasCachedModelTable ??= (bool)$this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", new ArrayClass([$this->schema->name, "ManagedObjectModel"])))->fetchColumn();
    }
    private(set) bool $hasPersistentHistoryTables {
        get => $this->hasPersistentHistoryTables ??= (bool)$this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name IN (?, ?)", new ArrayClass([$this->schema->name, "PersistentHistoryTransaction", "PersistentHistoryChange"])))->fetchColumn();
    }
    private(set) ?ManagedObjectModel $cachedModel {
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
    private ?PDO $pdo = null;
    private(set) bool $isOpen = false;

    public function __construct(public readonly ?SQLAdapter $adapter = null)
    {
    }

    public function __destruct()
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->disconnect();
    }

    public static function destroyPersistentStoreAtURL(URL $url, ?Dictionary $options = null): bool
    {
        !$options?->valueForKey(ReadOnlyPersistentStoreOption) ?: fatal_error("Cannot destroy a read only persistent store");
        $connection = new SQLConnection();
        $connection->schema = SQLSchema::schema($url->host);
        /** @noinspection PhpUnhandledExceptionInspection */
        return $connection->destroySchema();
    }

    public static function replacePersistentStoreAtURL(/** @noinspection PhpUnusedParameterInspection */ URL $destinationURL, ?Dictionary $destinationOptions, URL $sourceURL, ?Dictionary $sourceOptions): bool
    {
        return true;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
            if ($timeout = $this->sqlCore?->options?->valueForKey(PersistentStoreTimeoutOption)) {
                $options[PDO::ATTR_TIMEOUT] = $timeout;
            }
            $this->pdo = PDO::connect("mysql:host={$this->schema->host};charset={$this->schema->charset};unix_socket={$this->schema->socket};", $this->schema->credential->user, $this->schema->credential->password, $options);
        }
        return $this->pdo;
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
        if (SQLCore::$debugDefault) {
            debuglog(sprintf("CoreData: annotation: Connecting to %s database \"%s\"", SQLStoreType, $schemaName));
        }
        if ($this->createSchemaIfNeeded()) {
            return true;
        }
        $this->pdo()->exec("USE `$schemaName`");
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
        if (SQLCore::$debugDefault) {
            debuglog("CoreData: annotation: Disconnecting from sql database \"{$this->schema->name}\"");
        }
        $this->pdo = null;
        $this->isOpen = false;
        return true;
    }

    /**
     * @throws Exception
     */
    public function execute(SQLStatement $statement): PDOStatement
    {
        $time = absolute_time_get_current();
        if (SQLCore::$debugDefault) {
            $style = SQLStatementFormatterStyle::string;
            if (SQLCore::$debugDefault > 1) {
                $style |= SQLStatementFormatterStyle::arguments;
                if (SQLCore::$debugDefault > 2) {
                    $style |= SQLStatementFormatterStyle::prettyPrint;
                }
            }
            if (SQLCore::$coloredLoggingDefault) {
                $style |= SQLStatementFormatterStyle::highlighted;
            }
            debuglog(sprintf("CoreData: sql: \n%s", $statement->formatted($style)));
        }
        $pdo = $this->pdo();
        if ($statement->arguments->isEmpty) {
            $prepare = $pdo->query($statement->string);
            if (SQLCore::$debugDefault) {
                debuglog(sprintf("CoreData: annotation: total fetch execution time: %s for %s row(s)", human_readable_time(absolute_time_get_current() - $time), $prepare->rowCount()));
            }
            return $prepare;
        }
        $prepare = $pdo->prepare($statement->string);
        $prepare->execute($statement->arguments->map(function (mixed $e): mixed {
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
        if (SQLCore::$debugDefault) {
            debuglog(sprintf("CoreData: annotation: fetch execution time: %s for %s row(s)", human_readable_time(absolute_time_get_current() - $time), $prepare->rowCount()));
        }
        return $prepare;
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
            $entities = $this->sqlCore?->model?->entities?->filter(fn(SQLEntity $entity): bool => $entity->entityDescription->isPersistentHistoryEntity) ?? fatal_error();
            foreach ($entities as $entity) {
                $this->createTableForEntity($entity);
            }
            foreach ($entities as $entity) {
                $this->createIndexesForEntity($entity);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function dropHistoryTrackingTables(): void
    {
        if ($this->hasPersistentHistoryTables) {
            $entities = $this->sqlCore?->model?->entities?->filter(fn(SQLEntity $entity): bool => $entity->entityDescription->isPersistentHistoryEntity) ?? fatal_error();
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
        }
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
        return (int)$this->pdo()->lastInsertId();
    }

    /**
     * @throws Exception
     */
    public function fetchMaxPrimaryKey(string $entityName): int
    {
        $entity = $this->sqlCore?->model?->entitiesByName[$entityName] ?? fatal_error("Invalid argument: entity \"$entityName\" does not exists");
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
    private function decompressedModelWithData(string $compressedData): ?ManagedObjectModel
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
        }
    }

    /**
     * @throws Exception
     */
    private function createMetadata(): void
    {
        if (!$this->hasMetadataTable) {
            $this->execute(new SQLStatement("CREATE TABLE IF NOT EXISTS `PersistentStoreMetadata` (`metadataID` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT, `data` LONGBLOB NOT NULL, PRIMARY KEY (`metadataID`)) ENGINE={$this->schema->engine} DEFAULT CHARSET={$this->schema->charset} COLLATE={$this->schema->collation}"));
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
     * @psalm-suppress InvalidArgument
     * @throws Exception
     */
    private function createManyToManyTablesForEntities(ArrayClass $entities): void
    {
        $adapter = $this->adapter ?? fatal_error();
        /** @var Set<SQLStatement> $statements */
        $statements = new Set($entities)->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->manyToManyRelationships)->map(fn(SQLManyToMany $manyToMany): SQLStatement => $adapter->newCreateTableStatementForManyToMany($manyToMany));
        if (!$statements->isEmpty) {
            $this->execute(SQLStatement::merging(new ArrayClass($statements)));
        }
        /** @var Set<SQLStatement> $statements */
        $statements = new Set($entities)->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->manyToManyRelationships)->map(fn(SQLManyToMany $manyToMany): SQLStatement => $adapter->newCreateIndexesStatementForManyToMany($manyToMany));
        if (!$statements->isEmpty) {
            $this->execute(SQLStatement::merging(new ArrayClass($statements)));
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
    public function createSchema(): bool
    {
        $time = absolute_time_get_current();
        $model = $this->sqlCore?->model ?? fatal_error();
        $database = $this->schema->name;
        if (SQLCore::$debugDefault) {
            debuglog("CoreData: annotation: creating database \"$database\"");
        }
        $this->execute(new SQLStatement("CREATE DATABASE `$database`"));
        $this->execute(new SQLStatement("USE `$database`"));
        $entities = $model->entities->filter(fn(SQLEntity $entity): bool => $entity->isRootEntity && !$entity->entityDescription->isPersistentHistoryEntity);
        foreach ($entities as $entity) {
            $this->createTableForEntity($entity);
        }
        foreach ($entities as $entity) {
            $this->createIndexesForEntity($entity);
        }
        $this->createManyToManyTablesForEntities($entities);
        $this->saveCachedModel($model);
        if (SQLCore::$debugDefault) {
            debuglog("CoreData: annotation: database \"$database\" created, total execution time: " . human_readable_time(absolute_time_get_current() - $time));
        }
        return true;
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
