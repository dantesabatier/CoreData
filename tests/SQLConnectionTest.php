<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\SQLConnection;
use Sabatier\CoreData\SQLCore;
use Sabatier\CoreData\SQLStatement;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;
use const Sabatier\CoreData\PersistentHistoryTrackingKey;
use const Sabatier\CoreData\PersistentStoreIDOption;

/** @property string $code */
final class ConnRecord extends ManagedObject
{
}

/**
 * Tests for SQLConnection, the layer every SQL statement in the framework passes through.
 *
 * It is the seam between the generated SQL and PDO, and it owns three things nothing else does:
 * transaction state, the persistent-history tables, and the store's own bookkeeping (cached
 * model, metadata, schema). None of it can be checked without a real server — the whole class is
 * about what the database does in response — so these run against MariaDB like the migration
 * suites do.
 *
 * The transaction methods are the reason to start here. They are guarded rather than blindly
 * delegated: beginTransaction() refuses to nest, and commit()/rollBack() refuse to run with no
 * transaction open. Those guards are what make executeRequestUsingConnection safe to call from a
 * request that may or may not already be inside a transaction, so getting them wrong either
 * corrupts a nested save or throws where the framework expects a quiet false.
 */
final class SQLConnectionTest extends SQLMigrationTestCase
{
    private const string StoreID = "sql-connection-test-store";

    private ?ManagedObjectContext $context = null;
    private ?SQLCore $store = null;

    private static function model(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $record = new EntityDescription();
        $record->name = "ConnRecord";
        $record->managedObjectClassName = ConnRecord::class;
        $record->properties = new ArrayClass([$code]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$record]);
        return $model;
    }

    /**
     * A stack with history tracking on, since half of this class only exists when the history
     * tables do.
     *
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $coordinator = new PersistentStoreCoordinator(self::model());
        $store = $coordinator->addPersistentStoreWithType(
            PersistentStoreType::sql,
            null,
            $this->storeURL,
            new Dictionary([
                PersistentHistoryTrackingKey => true,
                PersistentStoreIDOption => self::StoreID,
            ]),
        );
        $this->assertInstanceOf(SQLCore::class, $store);
        $this->store = $store;
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;
    }

    #[Override]
    protected function tearDown(): void
    {
        // Clearing the coordinator is what drops the context's registration with the notification
        // centre, and with it the connection the store holds open; it is a side effect, not a
        // write whose value is later read. Doing it before dropping the reference is the point.
        $context = $this->context;
        $this->context = null;
        $this->store = null;
        if ($context) {
            $context->persistentStoreCoordinator = null;
        }

        parent::tearDown();
    }

    private function connection(): SQLConnection
    {
        $this->assertNotNull($this->store);
        return $this->store->queryGenerationTrackingConnection;
    }

    /**
     * Writes a record and saves, which is what produces a history transaction.
     *
     * @throws Exception
     */
    private function writeRecord(string $code): void
    {
        $this->assertNotNull($this->context);
        $record = new ConnRecord($this->context);
        $record->code = $code;
        $this->context->save();
    }

    // --- Transactions ---

    /**
     * The ordinary cycle, and the state each step reports. inTransaction() is what
     * executeRequestUsingConnection branches on, so it has to track reality rather than a flag
     * the connection keeps for itself.
     *
     * @throws Exception
     */
    public function testATransactionCanBeBegunAndCommitted(): void
    {
        $connection = $this->connection();
        $connection->connect();

        $this->assertFalse($connection->inTransaction(), "nothing is open to begin with");
        $this->assertTrue($connection->beginTransaction());
        $this->assertTrue($connection->inTransaction());
        $this->assertTrue($connection->commit());
        $this->assertFalse($connection->inTransaction(), "the commit closed it");
    }

    /**
     * Rolling back is the other exit, and it must leave the connection in the same clean state a
     * commit does.
     *
     * @throws Exception
     */
    public function testATransactionCanBeRolledBack(): void
    {
        $connection = $this->connection();
        $connection->connect();

        $this->assertTrue($connection->beginTransaction());
        $this->assertTrue($connection->rollBack());
        $this->assertFalse($connection->inTransaction());
    }

    /**
     * Beginning inside an open transaction reports false instead of nesting or raising. This is
     * the guard that lets a batch request open its own transaction without knowing whether a
     * save already did.
     *
     * @throws Exception
     */
    public function testBeginningATransactionInsideOneReportsFalse(): void
    {
        $connection = $this->connection();
        $connection->connect();
        $this->assertTrue($connection->beginTransaction());

        $this->assertFalse($connection->beginTransaction(), "a transaction does not nest");
        $this->assertTrue($connection->inTransaction(), "and the original is still open");

        $connection->rollBack();
    }

    /**
     * Committing or rolling back with nothing open is a quiet false, not an exception: callers
     * unwind through these on a failure path and must not have the cleanup replace the error
     * they were reporting.
     *
     * @throws Exception
     */
    public function testCommittingOrRollingBackWithoutATransactionReportsFalse(): void
    {
        $connection = $this->connection();
        $connection->connect();

        $this->assertFalse($connection->inTransaction());
        $this->assertFalse($connection->commit(), "nothing to commit");
        $this->assertFalse($connection->rollBack(), "nothing to roll back");
    }

    /**
     * A rolled-back write leaves nothing behind. This is the assertion that the transaction
     * methods drive the real database rather than only bookkeeping flags.
     *
     * @throws Exception
     */
    public function testARolledBackStatementLeavesNoRow(): void
    {
        $this->writeRecord("R-1");

        $connection = $this->connection();
        $connection->connect();
        $connection->beginTransaction();
        $connection->execute(new SQLStatement("INSERT INTO `ConnRecord` (`entityName`, `code`) VALUES (?, ?)", new ArrayClass(["ConnRecord", "R-2"])));
        $connection->rollBack();

        $codes = $this->freshContext(self::model())->fetch(ConnRecord::fetchRequest())->map(fn(ConnRecord $record): mixed => $record->valueForKey("code"))->array;
        $this->assertSame(["R-1"], $codes, "the rolled-back insert is gone and the committed one is not");
    }

    // --- Persistent history maintenance ---

    /**
     * With tracking on, a save writes a history transaction — which is what everything below
     * needs in order to have something to prune.
     *
     * @throws Exception
     */
    public function testASaveWritesAHistoryRow(): void
    {
        $connection = $this->connection();
        $connection->connect();
        $this->assertFalse($connection->hasHistoryRows(), "no history before anything is saved");

        $this->writeRecord("R-1");

        $this->assertTrue($connection->hasHistoryRows());
    }

    /**
     * Pruning by transaction id drops everything strictly older, which is how a store trims
     * history it has already handed to every reader.
     *
     * @throws Exception
     */
    public function testHistoryCanBePrunedByTransactionID(): void
    {
        $this->writeRecord("R-1");
        $this->writeRecord("R-2");

        $connection = $this->connection();
        $connection->connect();
        $this->assertTrue($connection->hasHistoryRows());

        // Anything below a very large id is everything.
        $connection->dropHistoryBeforeTransactionID(PHP_INT_MAX);

        $this->assertFalse($connection->hasHistoryRows(), "the whole history was older than the cut-off");
    }

    /**
     * Pruning by date is the same operation keyed on the timestamp. A cut-off in the future
     * removes everything; the distant past removes nothing, which is the half that proves the
     * comparison is really applied.
     *
     * @throws Exception
     */
    public function testHistoryCanBePrunedByDate(): void
    {
        $this->writeRecord("R-1");

        $connection = $this->connection();
        $connection->connect();

        $connection->dropHistoryBeforeDate(Date::distantPast());
        $this->assertTrue($connection->hasHistoryRows(), "nothing is older than the distant past");

        $connection->dropHistoryBeforeDate(Date::distantFuture());
        $this->assertFalse($connection->hasHistoryRows(), "everything is older than the distant future");
    }

    /**
     * A transaction number that was never written is absent, and zero is absent by definition —
     * the method short-circuits on it, since zero is what the framework uses for "no
     * transaction".
     *
     * @throws Exception
     */
    public function testAnAbsentHistoryTransactionIsReportedAsAbsent(): void
    {
        $this->writeRecord("R-1");

        $connection = $this->connection();
        $connection->connect();

        $this->assertFalse($connection->hasHistoryTransactionWithNumber(new Number(0)), "zero means no transaction");
        $this->assertFalse($connection->hasHistoryTransactionWithNumber(new Number(999999)), "a number nothing wrote is absent");
    }

    /**
     * Dropping the tracking tables removes the history wholesale, and asking for rows afterwards
     * has to answer false rather than fail on a missing table.
     *
     * @throws Exception
     */
    public function testDroppingTheTrackingTablesRemovesTheHistory(): void
    {
        $this->writeRecord("R-1");

        $connection = $this->connection();
        $connection->connect();
        $this->assertTrue($connection->hasHistoryRows());

        $connection->dropHistoryTrackingTables();

        $this->assertFalse($connection->hasHistoryRows(), "with the tables gone there are no rows to report");
    }

    // --- Store bookkeeping ---

    /**
     * Metadata round-trips through the store's own table. It is archived rather than stored as
     * columns, so a value that survives is evidence the archiver pair works, not just the SQL.
     *
     * @throws Exception
     */
    public function testMetadataRoundTrips(): void
    {
        $connection = $this->connection();

        /** @var Dictionary<mixed> $metadata */
        $metadata = $connection->fetchMetadata() ?? new Dictionary();
        $metadata["testKey"] = "testValue";
        $connection->saveMetadata($metadata);

        $readBack = $connection->fetchMetadata();
        $this->assertNotNull($readBack);
        $this->assertSame("testValue", $readBack["testKey"]);
    }

    /**
     * The largest primary key in use, which is what the store needs to keep allocating ids past
     * the rows already written. An empty table answers zero rather than failing.
     *
     * @throws Exception
     */
    public function testMaxPrimaryKeyTracksTheRowsWritten(): void
    {
        $connection = $this->connection();
        $connection->connect();

        $this->assertSame(0, $connection->fetchMaxPrimaryKey("ConnRecord"), "an empty entity has no keys in use");

        $this->writeRecord("R-1");
        $this->writeRecord("R-2");

        $this->assertGreaterThanOrEqual(2, $connection->fetchMaxPrimaryKey("ConnRecord"), "the key keeps up with the rows");
    }

    /**
     * The schema exists once a store has been opened over the database, which is what
     * createSchemaIfNeeded did on the way in.
     *
     * @throws Exception
     */
    public function testTheSchemaExistsAfterTheStoreIsOpened(): void
    {
        $connection = $this->connection();
        $connection->connect();

        $this->assertTrue($connection->hasSchema());
        $this->assertFalse($connection->createSchemaIfNeeded(), "a schema that already exists is not created again");
    }
}
