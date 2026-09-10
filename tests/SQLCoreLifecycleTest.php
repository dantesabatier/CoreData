<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PDO;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\SQLCore;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ProcessInfo;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\StoreTypeKey;
use const Sabatier\CoreData\StoreUUIDKey;

/** @property string $code */
final class LifecycleRecord extends ManagedObject
{
}

/**
 * Tests for the store-lifecycle half of SQLCore: the static entry points a caller reaches
 * without an open stack, and load/unload.
 *
 * These are the methods a host application uses around the edges of normal work — reading a
 * store's metadata before deciding to open it, tearing a store down, closing the connection —
 * and they were the uncovered ones because every other suite goes straight to opening a stack
 * and fetching through it.
 *
 * The destructive cases run against their own database rather than the shared test schema:
 * destroyPersistentStoreAtURL issues a DROP DATABASE, so pointing it at the schema the rest of
 * the suite uses would take the other tests with it. That is also why this case does not extend
 * SQLMigrationTestCase — it manages its own databases instead of borrowing that one.
 *
 * replacePersistentStoreAtURL is deliberately not covered here: it requires a
 * ManagedObjectModelURLOption pointing at a model bundle on disk, so a meaningful test needs a
 * .momd fixture rather than a hand-built model, and that belongs with the model-bundle suite.
 */
final class SQLCoreLifecycleTest extends TestCase
{
    /** A database this case owns outright, so dropping it disturbs nothing else. */
    private const string ScratchDatabase = "coredata_lifecycle_scratch";

    private PDO $pdo;

    private static function model(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $record = new EntityDescription();
        $record->name = "LifecycleRecord";
        $record->managedObjectClassName = LifecycleRecord::class;
        $record->properties = new ArrayClass([$code]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$record]);
        return $model;
    }

    /**
     * The schema the SQL layer will actually use. ProcessInfo caches the .env once per process,
     * so a test cannot choose its own name — it reads whichever one the process already has.
     */
    private static function schemaName(): string
    {
        return ProcessInfo::processInfo()->environment["SQL_SCHEMA_NAME"] ?? "coredata_migration_test";
    }

    #[Override]
    protected function setUp(): void
    {
        $this->pdo = new PDO("mysql:host=127.0.0.1", "root", null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // A clean schema per case. These tests write store metadata, and metadata is what the
        // store reads back when it opens — so one case's leftovers decide whether the next one
        // can open a store at all.
        $this->pdo->exec("DROP DATABASE IF EXISTS `" . self::schemaName() . "`");
        $this->pdo->exec("CREATE DATABASE `" . self::schemaName() . "`");
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->pdo->exec("DROP DATABASE IF EXISTS `" . self::ScratchDatabase . "`");
    }

    /**
     * Opens a stack over the process's schema and returns the store.
     *
     * @throws Exception
     */
    private function openStore(?ManagedObjectContext &$context = null): SQLCore
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $store = $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, new URL("sql://" . self::schemaName()));
        self::assertInstanceOf(SQLCore::class, $store);

        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $store;
    }

    /**
     * Metadata is readable without an open stack: the static accessor makes its own connection,
     * which is what lets a host inspect a store before committing to it.
     *
     * @throws Exception
     */
    public function testMetadataIsReadableWithoutAnOpenStack(): void
    {
        $context = null;
        $this->openStore($context);
        $this->release($context);

        $metadata = SQLCore::metadataForPersistentStore(new URL("sql://" . self::schemaName()));

        $this->assertNotNull($metadata[StoreTypeKey], "a store always reports its type");
    }

    /**
     * With nothing written yet, the accessor still answers rather than failing — it falls back
     * to the bare store type. A host asking about a store it has never opened is a normal case,
     * not an error.
     *
     * @throws Exception
     */
    public function testMetadataFallsBackToTheStoreTypeWhenThereIsNone(): void
    {
        $metadata = SQLCore::metadataForPersistentStore(new URL("sql://" . self::ScratchDatabase));

        $this->assertNotNull($metadata[StoreTypeKey]);
    }

    /**
     * Writing metadata through the static setter and reading it back through the static getter,
     * neither of which needs a coordinator.
     *
     * @throws Exception
     */
    public function testMetadataCanBeWrittenAndReadBackStatically(): void
    {
        $context = null;
        $this->openStore($context);
        $this->release($context);

        $url = new URL("sql://" . self::schemaName());
        $metadata = SQLCore::metadataForPersistentStore($url);
        $metadata["lifecycleKey"] = "lifecycleValue";

        // Opening a store creates the metadata table but writes nothing into it, so this read
        // comes back as the fallback: a Dictionary carrying the store type and NO StoreUUIDKey.
        // That matters because identifier is a non-nullable string and loadMetadata() assigns
        // metadata[StoreUUIDKey] to it directly — saving this dictionary as-is leaves the store
        // unopenable, with a TypeError on the next load. The identity is supplied here for that
        // reason, and the case below pins the failure mode itself.
        $this->assertNull($metadata[StoreUUIDKey], "a store that has never written metadata has no identity in it");
        $metadata[StoreUUIDKey] = "lifecycle-identity";

        $this->assertTrue(SQLCore::setMetadata($metadata, $url));
        $this->assertSame("lifecycleValue", SQLCore::metadataForPersistentStore($url)["lifecycleKey"]);
    }

    /**
     * setMetadata with null writes a fresh identity rather than clearing the record: it builds a
     * Dictionary with a store type and a freshly generated UUID. That is the safe path, and the
     * contrast that makes the unsafe one visible — passing a dictionary that simply lacks a UUID,
     * which is exactly the shape metadataForPersistentStore hands back when a store has none.
     *
     * @throws Exception
     */
    public function testSettingNullMetadataWritesAFreshIdentity(): void
    {
        $context = null;
        $this->openStore($context);
        $this->release($context);

        $url = new URL("sql://" . self::schemaName());
        $this->assertTrue(SQLCore::setMetadata(null, $url));

        $written = SQLCore::metadataForPersistentStore($url);
        $this->assertNotNull($written[StoreTypeKey]);
        $this->assertNotNull($written[StoreUUIDKey], "the generated identity is what keeps the store openable");
    }

    /**
     * unload closes the connection the store was holding, and load opens it again. This is the
     * pair a host uses to release a database handle without discarding the stack.
     *
     * @throws Exception
     */
    public function testAStoreCanBeUnloadedAndLoadedAgain(): void
    {
        $context = null;
        $store = $this->openStore($context);

        $this->assertTrue($store->unload(), "the open connection is closed");
        $this->assertTrue($store->load(), "and can be opened again");

        $this->release($context);
    }

    /**
     * Destroying a store drops its database outright. This runs against a scratch database of
     * its own, because pointing it at the shared test schema would delete the ground every other
     * case in the suite stands on.
     *
     * @throws Exception
     */
    public function testDestroyingAStoreDropsItsDatabase(): void
    {
        $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `" . self::ScratchDatabase . "`");
        $this->assertTrue($this->scratchDatabaseExists(), "the database is there to begin with");

        $this->assertTrue(SQLCore::destroyPersistentStoreAtURL(new URL("sql://" . self::ScratchDatabase)));

        $this->assertFalse($this->scratchDatabaseExists(), "and is gone afterwards");
    }

    /**
     * Destroying a store that was never created is not an error: the drop is conditional, so a
     * host can clean up without first checking.
     *
     * @throws Exception
     */
    public function testDestroyingAnAbsentStoreIsHarmless(): void
    {
        $this->pdo->exec("DROP DATABASE IF EXISTS `" . self::ScratchDatabase . "`");

        $this->assertTrue(SQLCore::destroyPersistentStoreAtURL(new URL("sql://" . self::ScratchDatabase)));
        $this->assertFalse($this->scratchDatabaseExists());
    }

    /** Detaches a context so the stack — and the connection its store holds — can be collected. */
    private function release(?ManagedObjectContext $context): void
    {
        if ($context) {
            $context->persistentStoreCoordinator = null;
        }
    }

    /** Whether this case's scratch database currently exists. */
    private function scratchDatabaseExists(): bool
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?");
        $statement->execute([self::ScratchDatabase]);
        return (bool)$statement->fetchColumn();
    }
}
