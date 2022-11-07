<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 14:09
 */

namespace Sabatier\CoreData;

use BackedEnum;
use Closure;
use Exception;
use InvalidArgumentException;
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
use Throwable;
use function Sabatier\Foundation\absolute_time_get_current;
use function Sabatier\Foundation\human_readable_time;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/** @internal */
class SQLConnection extends ObjectClass
{
    public readonly SQLSchema $schema;
    public SQLStoreRequestContext $requestContext;
    /** @noRector */
    public ?SQLCore $sqlCore;
    private string $bundleID;
    private ?PDO $pdo = null;
    private bool $open = false;

    public function __construct(public readonly ?SQLAdapter $adapter = null)
    {
        unset($this->schema);
        unset($this->sqlCore);
        unset($this->bundleID);
    }

    public function __get(string $name)
    {
        /** @psalm-suppress PossiblyNullArgument */
        return $this->$name = match ($name) {
            'schema' => new SQLSchema(ProcessInfo::processInfo()->environment['COREDATA_SQL_DATABASE_NAME'], ProcessInfo::processInfo()->environment['COREDATA_SQL_DATABASE_HOST'], new SQLCredential(ProcessInfo::processInfo()->environment['COREDATA_SQL_DATABASE_USER'], ProcessInfo::processInfo()->environment['COREDATA_SQL_DATABASE_PASSWORD'])),
            'sqlCore' => $this->adapter?->sqlCore,
            'bundleID' => Bundle::main()->bundleIdentifier ?? ProcessInfo::processInfo()->globallyUniqueString,
            default => $this->valueForUndefinedKey($name)
        };
    }

    public function __destruct()
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->disconnect();
    }

    public static function destroyPersistentStoreAtURL(/** @noinspection PhpUnusedParameterInspection */ URL $url, ?Dictionary $options = null): bool
    {
        $connection = new SQLConnection();
        /** @noinspection PhpUnhandledExceptionInspection */
        $connection->destroySchema();
        return true;
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
            $this->pdo = new PDO("mysql:host={$this->schema->host};charset={$this->schema->charset}", $this->schema->credential->user, $this->schema->credential->password, $options);
        }
        return $this->pdo;
    }

    /**
     * @throws Exception
     */
    public function connect(): bool
    {
        if ($this->isOpen()) {
            return true;
        }
        $this->open = true;
        $schemaName = $this->schema->name;
        if (SQLCore::$debugDefault) {
            error_log(sprintf('CoreData: annotation: Connecting to %s database "%s"', SQLStoreType, $schemaName));
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
        if (!$this->isOpen()) {
            return true;
        }
        if (SQLCore::$debugDefault) {
            error_log("CoreData: annotation: Disconnecting from sql database \"{$this->schema->name}\"");
        }
        $this->pdo = null;
        $this->open = false;
        return true;
    }

    /**
     * @throws Exception
     */
    public function execute(SQLStatement $statement): PDOStatement
    {
        try {
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
                error_log(sprintf("CoreData: sql: \n%s", $statement->formatted($style)));
            }
            $pdo = $this->pdo();
            if ($statement->arguments->isEmpty()) {
                $prepare = $pdo->query($statement->string);
                if (SQLCore::$debugDefault) {
                    error_log(sprintf("CoreData: annotation: total fetch execution time: %s for %s row(s)", human_readable_time(absolute_time_get_current() - $time), $prepare->rowCount()));
                }
                return $prepare;
            }
            $prepare = $pdo->prepare($statement->string);
            $prepare->execute($statement->arguments->map(function (mixed $e): mixed {
                if ($e instanceof Nil || $e instanceof BackedEnum) {
                    return $e->value;
                } elseif ($e instanceof ManagedObjectID) {
                    return $e->referenceObject;
                } elseif ($e instanceof Number) {
                    if (is_bool($e->value)) {
                        return $e->intValue;
                    }
                    return $e->value;
                } elseif (is_bool($e)) {
                    return (int)$e;
                }
                return $e;
            })->toArray());
            if (SQLCore::$debugDefault) {
                error_log(sprintf("CoreData: annotation: fetch execution time: %s for %s row(s)", human_readable_time(absolute_time_get_current() - $time), $prepare->rowCount()));
            }
            return $prepare;
        } catch (Throwable $throwable) {
            $throwableClass = $throwable::class;
            throw new $throwableClass($throwable->getMessage(), (int)$throwable->getCode());
        }
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
        $execute = $this->execute(new SQLStatement("SELECT COUNT(*) FROM `$tableName`"));
        return (int)$execute->fetchColumn();
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
     * @throws Exception
     */
    private function insertChanges(Set $changedObjectIDs, PersistentHistoryChangeType $type, int $transactionID, ManagedObjectContext $context): void
    {
        $statement = SQLStatement::merging(new ArrayClass($changedObjectIDs->map(function (ManagedObjectID $changedObjectID) use ($type, $transactionID, $context): SQLStatement {
            $valueTransformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName);
            $managedObject = $context->object($changedObjectID);
            $tombstone = $type === PersistentHistoryChangeType::delete ? $managedObject->changedValues()->filter(fn(mixed $value, string $key): bool => $managedObject->entity->attributesByName->contains(fn(AttributeDescription $attribute): bool => $attribute->name === $key && $attribute->preservesValueInHistoryOnDeletion)) : null;
            $updatedProperties = $type === PersistentHistoryChangeType::update ? new Set($managedObject->changedValuesForCurrentEvent()->compactMap(fn(mixed $value, string $key): ?string => $managedObject->entity->propertiesByName->valueForKey($key)?->name)) : null;
            return new SQLStatement("INSERT INTO `PersistentHistoryChange` (`changedObjectID`, `changeType`, `tombstone`, `updatedProperties`, `transactionID`) VALUES (?, ?, ?, ?, ?)", new ArrayClass([$valueTransformer?->transformedValue($changedObjectID), $type, $valueTransformer?->transformedValue($tombstone), $valueTransformer?->transformedValue($updatedProperties), $transactionID]));
        })));
        $this->execute($statement);
    }

    /**
     * @throws Exception
     */
    private function insertManagedObjectBlock(Closure $block, SQLEntity $entity, bool $includeOnConflict = false): int
    {
        $requestContext = $this->requestContext;
        /** @var ArrayClass<ManagedObject> $insertedObjects */
        $insertedObjects = new ArrayClass();
        while (true) {
            $insertedObject = EntityDescription::insertNewObject($entity->tableName, $requestContext->context);
            if (!$block($insertedObject)) {
                break;
            }
            $insertedObjects->append($insertedObject);
        }
        return $this->insertArray($insertedObjects, $entity, $includeOnConflict);
    }

    /**
     * @throws Exception
     */
    private function insertDictionaryBlock(/** @noinspection PhpSameParameterValueInspection */ Closure $block, SQLEntity $entity, bool $includeOnConflict = false): int
    {
        return $this->insertManagedObjectBlock(function (ManagedObject $insertedObject) use ($block): bool {
            $dictionary = new Dictionary();
            $ok = $block($dictionary);
            $insertedObject->setValuesForKeys($dictionary);
            return $ok;
        }, $entity, $includeOnConflict);
    }

    /**
     * @throws Exception
     */
    private function insertArray(/** @noinspection PhpUnusedParameterInspection */ ArrayClass $array, SQLEntity $entity, bool $includeOnConflict = false): int
    {
        /** @var SQLBatchInsertRequestContext $requestContext */
        $requestContext = $this->requestContext;
        /** @var ArrayClass<string> $columnNames */
        $columnNames = new ArrayClass();
        $columnNames->appendContentsOf([$entity->entityKey->columnName]);
        /** @var ManagedObject|Dictionary $element */
        $element = $array->first() ?? throw new InvalidArgumentException();
        if ($element instanceof ManagedObject) {
            $columnNames->appendContentsOf($element->changedValuesForCurrentEvent()->keys);
        }
        $columns = $entity->columnsToCreate->filter(fn(SQLColumn $column): bool => $columnNames->containsElement($column->columnName));
        $string = "INSERT INTO `$entity->tableName` ({$columns->map(fn(SQLColumn $column): string => "`$column->columnName`")->join(', ')}) VALUES " . ArrayClass::repeating("(" . ArrayClass::repeating('?', $columns->count())->join(', ') . ")", $array->count())->join(', ') . " RETURNING `{$entity->primaryKey->columnName}`";
        $arguments = $array->flatMap(fn(ManagedObject|Dictionary $object): ArrayClass => $columns->map(function (SQLColumn $column) use ($entity, $object): mixed {
            if ($column instanceof SQLEntityKey) {
                return $entity->tableName;
            } elseif ($column instanceof SQLAttribute) {
                $value = $object->valueForKey($column->name);
                ManagedObject::coerceValue($value, $column->attributeDescription, true);
                return $value;
            }
            return $object->valueForKey($column->name);
        }));
        $statement = new SQLStatement($string, $arguments);
        $execute = $this->execute($statement);
        /** @return ArrayClass<ManagedObjectID> */
        $objectIDs = function () use ($requestContext, $entity, $execute): ArrayClass {
            /** @var ArrayClass<ManagedObjectID> $managedObjectIDs */
            $managedObjectIDs = new ArrayClass();
            do {
                while ($data = $execute->fetch()) {
                    $managedObjectIDs->append($requestContext->sqlCore->newObjectID($entity->entityDescription, $data[$entity->primaryKey->columnName]));
                }
            } while ($execute->nextRowset() && $execute->columnCount());
            return $managedObjectIDs;
        };
        $requestContext->result = match ($requestContext->request->resultType) {
            BatchInsertRequestResultType::statusOnly => new ArrayClass([new Number(true)]),
            BatchInsertRequestResultType::objectIDs => $objectIDs(),
            BatchInsertRequestResultType::count => new ArrayClass([new Number($execute->rowCount())]),
        };
        if ($requestContext->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey)) {
            $this->createHistoryTrackingTables();
            $transactionID = $this->fetchMaxPrimaryKey('PersistentHistoryTransaction') + 1;
            $insertedObjectIDs = $requestContext->result;
            if ($requestContext->request->resultType !== BatchInsertRequestResultType::objectIDs) {
                $insertedObjectIDs = $objectIDs();
            }
            $this->insertBatchInserts($insertedObjectIDs, $transactionID);
            return $transactionID;
        }
        return 0;
    }

    /**
     * @throws Exception
     */
    public function insertTransactionForRequestContext(SQLStoreRequestContext $requestContext): int
    {
        $this->requestContext = $requestContext;
        if ($requestContext instanceof SQLSaveChangesRequestContext) {
            if (!$requestContext->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey) || $requestContext->hasHistoryTracking) {
                return 0;
            }
            $insertedObjects = $requestContext->request->insertedObjects ?? new Set();
            $updatedObjects = $requestContext->request->updatedObjects ?? new Set();
            $deletedObjects = $requestContext->request->deletedObjects ?? new Set();
            if ($insertedObjects->isEmpty() && $updatedObjects->isEmpty() && $deletedObjects->isEmpty()) {
                return 0;
            }
            $this->createHistoryTrackingTables();
            $transactionID = $this->fetchMaxPrimaryKey('PersistentHistoryTransaction') + 1;
            $statement = new SQLStatement("INSERT INTO `PersistentHistoryTransaction` (`transactionID`, `author`, `bundleID`, `contextName`, `processID`, `storeID`) VALUES (?, ?, ?, ?, ?, ?)", new ArrayClass([$transactionID, $requestContext->context->transactionAuthor, $this->bundleID, $requestContext->context->name, ProcessInfo::processInfo()->globallyUniqueString, $requestContext->sqlCore->identifier]));
            $this->execute($statement);
            if (!$insertedObjects->isEmpty()) {
                $this->insertChanges($insertedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID), PersistentHistoryChangeType::insert, $transactionID, $requestContext->context);
            }
            if (!$updatedObjects->isEmpty()) {
                $this->insertChanges($updatedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID), PersistentHistoryChangeType::update, $transactionID, $requestContext->context);
            }
            if (!$deletedObjects->isEmpty()) {
                $this->insertChanges($deletedObjects->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID), PersistentHistoryChangeType::delete, $transactionID, $requestContext->context);
            }
            return $transactionID;
        } elseif ($requestContext instanceof SQLBatchInsertRequestContext) {
            /** @var SQLEntity $entity */
            $entity = $requestContext->sqlCore->model->entitiesByName[$requestContext->request->entity->name];
            if ($objectsToInsert = $requestContext->request->objectsToInsert) {
                return $this->insertArray($objectsToInsert, $entity);
            } elseif ($dictionaryHandler = $requestContext->request->dictionaryHandler) {
                return $this->insertDictionaryBlock($dictionaryHandler, $entity);
            } elseif ($managedObjectHandler = $requestContext->request->managedObjectHandler) {
                return $this->insertManagedObjectBlock($managedObjectHandler, $entity);
            }
        } elseif ($requestContext instanceof SQLBatchUpdateRequestContext) {
            $affectedObjectIDs = $requestContext->affectedObjectIDs;
            if ($requestContext->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey) && !$affectedObjectIDs->isEmpty()) {
                $this->createHistoryTrackingTables();
                $transactionID = $this->fetchMaxPrimaryKey('PersistentHistoryTransaction') + 1;
                $this->insertUpdates($affectedObjectIDs, $transactionID, new Set($requestContext->request->propertiesToUpdate?->keys ?? []));
                return $transactionID;
            }
        } elseif ($requestContext instanceof SQLBatchDeleteRequestContext) {
            if ($requestContext->sqlCore->options?->valueForKey(PersistentHistoryTrackingKey) && !$requestContext->affectedObjectIDs->isEmpty()) {
                $this->createHistoryTrackingTables();
                $transactionID = $this->fetchMaxPrimaryKey('PersistentHistoryTransaction') + 1;
                $this->insertBatchDeleteChangesForTransactionID($transactionID);
                return $transactionID;
            }
        }
        return 0;
    }

    /**
     * @throws Exception
     */
    public function hasHistoryRows(): bool
    {
        if ($this->hasPersistentHistoryTables()) {
            return $this->tableHasRows('PersistentHistoryTransaction');
        }
        return false;
    }

    /**
     * @throws Exception
     */
    public function dropHistoryBeforeTransactionID(int $transactionID): void
    {
        if ($this->hasPersistentHistoryTables()) {
            $this->execute(new SQLStatement("DELETE FROM `PersistentHistoryTransaction` WHERE `transactionID` < ?", new ArrayClass([$transactionID])));
        }
    }

    /**
     * @throws Exception
     */
    public function hasHistoryTransactionWithNumber(Number $transactionNumber): bool
    {
        if ($transactionNumber->intValue && $this->hasPersistentHistoryTables()) {
            $execute = $this->execute(new SQLStatement("SELECT COUNT(`transactionID`) FROM `PersistentHistoryTransaction` WHERE `transactionID` = ?", new ArrayClass([$transactionNumber])));
            return (bool)$execute->fetchColumn();
        }
        return false;
    }

    /**
     * @throws Exception
     */
    private function hasPersistentHistoryTables(): bool
    {
        $execute = $this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name IN (?, ?)", new ArrayClass([$this->schema->name, 'PersistentHistoryTransaction', 'PersistentHistoryChange'])));
        return (bool)$execute->fetchColumn();
    }

    /**
     * @throws Exception
     */
    private function createHistoryTrackingTables(): void
    {
        if (!$this->hasPersistentHistoryTables()) {
            $entities = $this->sqlCore?->model?->entities?->filter(fn(SQLEntity $entity): bool => $entity->entityDescription->isPersistentHistoryEntity) ?? throw new InvalidArgumentException();
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
        if ($this->hasPersistentHistoryTables()) {
            $entities = $this->sqlCore?->model?->entities?->filter(fn(SQLEntity $entity): bool => $entity->entityDescription->isPersistentHistoryEntity) ?? throw new InvalidArgumentException();
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
        $entity = $this->sqlCore?->model?->entitiesByName[$entityName] ?? throw new InvalidArgumentException("invalid argument: entity \"$entityName\" does not exists");
        $execute = $this->execute(new SQLStatement("SELECT MAX({$entity->primaryKey->columnName}) FROM `$entity->tableName`"));
        return (int)$execute->fetchColumn();
    }

    /**
     * @throws Exception
     */
    public function hasCachedModelTable(): bool
    {
        $execute = $this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", new ArrayClass([$this->schema->name, 'ManagedObjectModel'])));
        return (bool)$execute->fetchColumn();
    }

    /**
     * @throws Exception
     */
    public function saveCachedModel(SQLModel $model): void
    {
        $managedObjectModel = $model->managedObjectModel;
        $this->createCachedModelTable();
        $this->execute(new SQLStatement("INSERT INTO `ManagedObjectModel` (`modelID`, `data`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `modelID` = VALUES(`modelID`), `data` = VALUES(`data`)", new ArrayClass([1, $this->compressedDataWithModel($managedObjectModel)])));
        /** @var Dictionary<mixed> $metadata */
        $metadata = $this->adapter?->sqlCore?->metadata ?? new Dictionary([StoreTypeKey => SQLStoreType]);
        $metadata[StoreModelVersionHashesKey] = $managedObjectModel->versionHash;
        $this->saveMetadata($metadata);
    }

    /**
     * @throws Exception
     */
    private function compressedDataWithModel(ManagedObjectModel $model): ?string
    {
        if ($data = gzcompress(KeyedArchiver::archivedData($model->jsonSerialize()), 9)) {
            return $data;
        }
        return null;
    }

    /**
     * @throws Exception
     */
    public function fetchCachedModel(): ?ManagedObjectModel
    {
        $this->connect();
        $this->createCachedModelTable();
        $execute = $this->execute(new SQLStatement("SELECT * FROM `ManagedObjectModel`"));
        if ($array = $execute->fetch()) {
            return $this->decompressedModelWithData($array['data']);
        }
        return null;
    }

    /**
     * @throws Exception
     */
    private function decompressedModelWithData(string $compressedData): ?ManagedObjectModel
    {
        if ($data = gzuncompress($compressedData)) {
            return ManagedObjectModel::newModel($data);
        }
        return null;
    }

    /**
     * @throws Exception
     */
    private function createCachedModelTable(): void
    {
        if (!$this->hasCachedModelTable()) {
            $this->execute(new SQLStatement("CREATE TABLE `ManagedObjectModel` (`modelID` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT, `data` BLOB NOT NULL, PRIMARY KEY (`modelID`)) ENGINE={$this->schema->engine} DEFAULT CHARSET={$this->schema->charset} COLLATE={$this->schema->collation}"));
        }
    }

    /**
     * @throws Exception
     */
    private function createMetadata(): void
    {
        if (!$this->hasMetadataTable()) {
            $this->execute(new SQLStatement("CREATE TABLE `PersistentStoreMetadata` (`metadataID` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT, `data` BLOB NOT NULL, PRIMARY KEY (`metadataID`)) ENGINE={$this->schema->engine} DEFAULT CHARSET={$this->schema->charset} COLLATE={$this->schema->collation}"));
        }
    }

    /**
     * @throws Exception
     */
    public function hasMetadataTable(): bool
    {
        $execute = $this->execute(new SQLStatement("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", new ArrayClass([$this->schema->name, 'PersistentStoreMetadata'])));
        return (bool)$execute->fetchColumn();
    }

    /**
     * @throws Exception
     */
    private function decompressedMetadataWithData(string $compressedData): ?Dictionary
    {
        if ($data = gzuncompress($compressedData)) {
            return KeyedUnarchiver::unarchiveTopLevelObjectWithData($data);
        }
        return null;
    }

    /**
     * @throws Exception
     */
    public function fetchMetadata(): ?Dictionary
    {
        $this->connect();
        $this->createMetadata();
        $execute = $this->execute(new SQLStatement("SELECT * FROM `PersistentStoreMetadata`"));
        if ($array = $execute->fetch()) {
            return $this->decompressedMetadataWithData($array['data']);
        }
        return null;
    }

    /**
     * @throws Exception
     */
    private function compressedDataWithMetadata(Dictionary $metadata): ?string
    {
        if ($data = gzcompress(KeyedArchiver::archivedData($metadata), 9)) {
            return $data;
        }
        return null;
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
     * @throws Exception
     */
    private function createManyToManyTablesForEntities(ArrayClass $entities): void
    {
        $adapter = $this->adapter ?? throw new InvalidArgumentException();
        /** @var Set<SQLStatement> $statements */
        $statements = (new Set($entities))->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->manyToManyRelationships)->map(fn(SQLManyToMany $manyToMany): SQLStatement => $adapter->newCreateTableStatementForManyToMany($manyToMany));
        if (!$statements->isEmpty()) {
            $this->execute(SQLStatement::merging(new ArrayClass($statements)));
        }
        /** @var Set<SQLStatement> $statements */
        $statements = (new Set($entities))->flatMap(fn(SQLEntity $entity): ArrayClass => $entity->manyToManyRelationships)->map(fn(SQLManyToMany $manyToMany): SQLStatement => $adapter->newCreateIndexesStatementForManyToMany($manyToMany));
        if (!$statements->isEmpty()) {
            $this->execute(SQLStatement::merging(new ArrayClass($statements)));
        }
    }

    /**
     * @throws Exception
     */
    public function hasSchema(): bool
    {
        /** @noinspection SqlShadowingAlias */
        $execute = $this->execute(new SQLStatement("SELECT COUNT(*) schema_name FROM information_schema.schemata WHERE schema_name = ?", new ArrayClass([$this->schema->name])));
        return (bool)$execute->fetchColumn();
    }

    /**
     * @throws Exception
     */
    public function createSchema(): bool
    {
        try {
            $time = absolute_time_get_current();
            $model = $this->sqlCore?->model ?? throw new InvalidArgumentException();
            $database = $this->schema->name;
            if (SQLCore::$debugDefault) {
                error_log("CoreData: annotation: creating database \"$database\"");
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
                error_log("CoreData: annotation: database \"$database\" created, total execution time: " . human_readable_time(absolute_time_get_current() - $time));
            }
            return true;
        } catch (Throwable $throwable) {
            $throwableClass = $throwable::class;
            throw new $throwableClass($throwable->getMessage(), (int)$throwable->getCode());
        }
    }

    /**
     * @throws Exception
     */
    public function createSchemaIfNeeded(): bool
    {
        try {
            if (!$this->hasSchema()) {
                return $this->createSchema();
            }
            return false;
        } catch (Throwable $throwable) {
            $throwableClass = $throwable::class;
            throw new $throwableClass($throwable->getMessage(), (int)$throwable->getCode());
        }
    }

    /**
     * @throws Exception
     */
    public function destroySchema(): bool
    {
        $this->execute(new SQLStatement("DROP DATABASE IF EXISTS `{$this->schema->name}`"));
        return true;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
