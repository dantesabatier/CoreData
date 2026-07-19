<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\InferMappingModelAutomaticallyOption;
use const Sabatier\CoreData\MigratePersistentStoresAutomaticallyOption;

/**
 * Base class for lightweight-migration tests that exercise SQLStoreMigrator against a real
 * MariaDB/MySQL server.
 *
 * These tests run the actual production migration path: a database is bootstrapped from a
 * "v1" model (which archives its cached model into the ManagedObjectModel table), then a
 * coordinator is opened over the SAME database with a "v2" model and the automatic-migration
 * options set. The coordinator detects the model incompatibility and drives
 * SQLInPlaceMigrationManager -> SQLStoreMigrator, altering the live schema in place. The
 * assertions then read INFORMATION_SCHEMA (the resulting DDL) and re-fetch objects (data
 * survival) to pin the migrator's behavior.
 *
 * Connection: the SQL layer resolves the database name from a ".env" file in the project
 * root (ProcessInfo reads SQL_SCHEMA_NAME from there; the process environment is not
 * consulted). This case therefore writes a temporary .env for the duration of the test,
 * backing up and restoring any pre-existing file. Host/user default to 127.0.0.1/root with
 * no password (SQLSchema's own defaults), matching a stock local server.
 *
 * The database name is fixed (not random) because ProcessInfo caches the parsed environment
 * once per process; each test instead guarantees isolation by dropping and recreating the
 * database around every run.
 */
abstract class SQLMigrationTestCase extends TestCase
{
    /** The fixed schema/database name used for every migration test. */
    protected const string DATABASE_NAME = "coredata_migration_test";

    private string $envPath;
    private ?string $envBackup = null;
    protected PDO $pdo;
    protected URL $storeURL;

    protected function setUp(): void
    {
        $this->storeURL = new URL("sql://" . static::DATABASE_NAME);

        // Point the SQL layer at our test database via a temporary .env, preserving any
        // developer .env already present in the project root.
        $this->envPath = dirname(__DIR__) . "/.env";
        if (is_file($this->envPath)) {
            $this->envBackup = (string)file_get_contents($this->envPath);
        }
        file_put_contents($this->envPath, "SQL_SCHEMA_NAME=" . static::DATABASE_NAME . "\n");

        $this->pdo = new PDO(
            "mysql:host=127.0.0.1",
            "root",
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        // Clean slate: no leftover schema from an earlier aborted run.
        $this->dropDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropDatabase();

        if ($this->envBackup !== null) {
            file_put_contents($this->envPath, $this->envBackup);
        } elseif (is_file($this->envPath)) {
            unlink($this->envPath);
        }
    }

    private function dropDatabase(): void
    {
        $this->pdo->exec("DROP DATABASE IF EXISTS `" . static::DATABASE_NAME . "`");
    }

    /**
     * Builds the database from the source ("v1") model: creating the coordinator and adding
     * an SQL store auto-creates the schema and archives the model as the cached model. The
     * returned context is bound to that v1 stack so the caller can insert fixture data.
     */
    protected function bootstrap(ManagedObjectModel $sourceModel): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator($sourceModel);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * Opens the same database with the destination ("v2") model and the automatic-migration
     * options, reproducing the production trigger: the coordinator sees the cached (v1) model
     * is incompatible with $destinationModel and runs the in-place SQL migration before
     * returning. The returned context is bound to the migrated (v2) stack.
     */
    protected function migrateTo(ManagedObjectModel $destinationModel): ManagedObjectContext
    {
        $options = new Dictionary([
            MigratePersistentStoresAutomaticallyOption => true,
            InferMappingModelAutomaticallyOption => true,
        ]);
        $coordinator = new PersistentStoreCoordinator($destinationModel);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $this->storeURL, $options);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * Opens a brand-new stack over the already-migrated database, the way a real application
     * does after migration completes. Use this — not the context returned by migrateTo() — to
     * read back migrated data: the migrating coordinator still holds row-cache snapshots taken
     * against the pre-migration schema, so fetching through it can miss columns that were
     * renamed (or otherwise reshaped) during the migration. A fresh stack loads the current
     * schema and its data cleanly.
     */
    protected function freshContext(ManagedObjectModel $model): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator($model);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    // --- Schema introspection helpers (read the live DDL that the migrator produced) ---

    /**
     * @return list<string> the column names of $tableName in declaration order
     */
    protected function columnNames(string $tableName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
        );
        $stmt->execute([static::DATABASE_NAME, $tableName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function hasColumn(string $tableName, string $columnName): bool
    {
        return in_array($columnName, $this->columnNames($tableName), true);
    }

    /**
     * Returns the DATA_TYPE (e.g. "varchar", "int", "bigint", "datetime") of a column, or
     * null if the column does not exist.
     */
    protected function columnType(string $tableName, string $columnName): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        );
        $stmt->execute([static::DATABASE_NAME, $tableName, $columnName]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : (string)$type;
    }

    /**
     * Returns whether a column is nullable ("YES" -> true) or null if the column is absent.
     */
    protected function columnIsNullable(string $tableName, string $columnName): ?bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        );
        $stmt->execute([static::DATABASE_NAME, $tableName, $columnName]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value === "YES";
    }

    protected function tableExists(string $tableName): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
        );
        $stmt->execute([static::DATABASE_NAME, $tableName]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Reads a single column's values straight from the table via SQL, bypassing the object
     * graph. Preferred for asserting data survival after a migration: it reflects exactly what
     * the migrator wrote to disk, without the row-cache/faulting behavior of a fetch through a
     * coordinator (which can lag a schema change made underneath it).
     *
     * @return list<string> the column's values, one per row, cast to string
     */
    protected function columnValues(string $tableName, string $columnName): array
    {
        // Identifiers cannot be bound as parameters; they originate from test code (trusted),
        // not user input, and are wrapped in backticks.
        $sql = sprintf(
            "SELECT `%s` FROM `%s`.`%s`",
            str_replace("`", "``", $columnName),
            str_replace("`", "``", static::DATABASE_NAME),
            str_replace("`", "``", $tableName),
        );
        return array_map(strval(...), $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string> the names of every foreign-key constraint on $tableName
     */
    protected function foreignKeyNames(string $tableName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        );
        $stmt->execute([static::DATABASE_NAME, $tableName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
