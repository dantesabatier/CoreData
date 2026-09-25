<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\BatchDeleteRequest;
use Sabatier\CoreData\BatchInsertRequest;
use Sabatier\CoreData\BatchInsertRequestResultType;
use Sabatier\CoreData\BatchUpdateRequest;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentHistoryChange;
use Sabatier\CoreData\PersistentHistoryChangeRequest;
use Sabatier\CoreData\PersistentHistoryChangeType;
use Sabatier\CoreData\PersistentHistoryResult;
use Sabatier\CoreData\PersistentHistoryResultType;
use Sabatier\CoreData\PersistentHistoryTransaction;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;
use const Sabatier\CoreData\PersistentHistoryTrackingKey;
use const Sabatier\CoreData\PersistentStoreIDOption;

/**
 * @property string $sku
 * @property int $qty
 */
final class HistoryWidget extends ManagedObject
{
}

/**
 * Tests that the batch operations record persistent history.
 *
 * A batch request never travels through the object graph, which is what makes it cheap — but it
 * does still write a history transaction, and that is what lets another process discover the
 * change. If a batch wrote rows without recording them, a peer reading history would conclude
 * nothing happened and drift out of sync silently, with no error anywhere.
 *
 * These three code paths (insertBatchInserts, insertUpdates,
 * insertBatchDeleteChangesForTransactionID) only run with PersistentHistoryTrackingKey enabled,
 * which is why the plain batch suite never reaches them: it opens a store without tracking. The
 * distinction is the whole point of this file.
 *
 * History is read back through PersistentHistoryChangeRequest and the model, never by querying
 * the history tables directly, so what is asserted is what a consumer would actually observe.
 */
final class SQLBatchHistoryTest extends SQLMigrationTestCase
{
    private const string StoreID = "sql-batch-history-test-store";

    private ?ManagedObjectContext $context = null;

    private static function model(): ManagedObjectModel
    {
        $sku = new AttributeDescription();
        $sku->name = "sku";
        $sku->type = AttributeType::string;

        $qty = new AttributeDescription();
        $qty->name = "qty";
        $qty->type = AttributeType::integer32;
        $qty->isOptional = true;

        $widget = new EntityDescription();
        $widget->name = "HistoryWidget";
        $widget->managedObjectClassName = HistoryWidget::class;
        $widget->properties = new ArrayClass([$sku, $qty]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget]);
        return $model;
    }

    /**
     * A stack with history tracking on. Everything under test only exists when this option is
     * set, so it is the premise of the file rather than a detail of one case.
     *
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(
            PersistentStoreType::sql,
            null,
            $this->storeURL,
            new Dictionary([
                PersistentHistoryTrackingKey => true,
                PersistentStoreIDOption => self::StoreID,
            ]),
        );
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;
        $this->context->transactionAuthor = "batch-author";
    }

    #[Override]
    protected function tearDown(): void
    {
        $context = $this->context;
        $this->context = null;
        if ($context) {
            $context->persistentStoreCoordinator = null;
        }

        parent::tearDown();
    }

    private function context(): ManagedObjectContext
    {
        $this->assertNotNull($this->context);
        return $this->context;
    }

    /**
     * Every history transaction recorded so far, oldest first.
     *
     * @return list<PersistentHistoryTransaction>
     * @throws Exception
     */
    private function transactions(): array
    {
        $request = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(null);
        $request->resultType = PersistentHistoryResultType::transactionsAndChanges;
        $result = $this->context()->execute($request);

        $this->assertInstanceOf(PersistentHistoryResult::class, $result);
        $this->assertInstanceOf(ArrayClass::class, $result->result);

        /** @var list<PersistentHistoryTransaction> $transactions */
        $transactions = $result->result->array;
        usort($transactions, fn(PersistentHistoryTransaction $left, PersistentHistoryTransaction $right): int => $left->transactionNumber <=> $right->transactionNumber);
        return $transactions;
    }

    /**
     * The change types recorded across every transaction, in order.
     *
     * @return list<PersistentHistoryChangeType>
     * @throws Exception
     */
    private function changeTypes(): array
    {
        $types = [];
        foreach ($this->transactions() as $transaction) {
            foreach ($transaction->changes ?? new ArrayClass() as $change) {
                $this->assertInstanceOf(PersistentHistoryChange::class, $change);
                $types[] = $change->changeType;
            }
        }
        return $types;
    }

    /**
     * Inserts rows through a batch request.
     *
     * @param list<array{sku: string, qty: int}> $rows
     * @throws Exception
     */
    private function batchInsert(array $rows): void
    {
        $queue = new ArrayClass($rows);
        $this->context()->execute(new BatchInsertRequest(
            HistoryWidget::entity(),
            dictionaryHandler: static function (Dictionary $snapshot) use ($queue): bool {
                if ($queue->isEmpty) {
                    return false;
                }
                /** @var array<string, mixed> $row */
                $row = $queue->popFirst();
                foreach ($row as $key => $value) {
                    $snapshot[$key] = $value;
                }
                return true;
            },
            resultType: BatchInsertRequestResultType::objectIDs,
        ));
    }

    /**
     * A batch insert records one transaction whose changes are all insertions. The object IDs are
     * what a peer resolves to find the new rows, so a transaction with no changes would be as
     * useless as none at all.
     *
     * @throws Exception
     */
    public function testABatchInsertRecordsInsertChanges(): void
    {
        $this->batchInsert([
            ["sku" => "H-1", "qty" => 1],
            ["sku" => "H-2", "qty" => 2],
        ]);

        $transactions = $this->transactions();
        $this->assertCount(1, $transactions, "one batch, one transaction");
        $this->assertSame("batch-author", $transactions[0]->author, "the author travels with it");
        $this->assertSame([PersistentHistoryChangeType::insert, PersistentHistoryChangeType::insert], $this->changeTypes());
    }

    /**
     * A batch update records its own transaction, typed as an update, on top of whatever the
     * insert left. Two operations must not collapse into one transaction: a reader replays them
     * in order.
     *
     * @throws Exception
     */
    public function testABatchUpdateRecordsUpdateChanges(): void
    {
        $this->batchInsert([["sku" => "H-1", "qty" => 1]]);

        $request = new BatchUpdateRequest(HistoryWidget::entity());
        $request->predicate = Predicate::format("sku == %@", new ArrayClass(["H-1"]));
        $request->propertiesToUpdate = new Dictionary(["qty" => 42]);
        $this->context()->execute($request);

        $this->assertCount(2, $this->transactions(), "the insert and the update are separate transactions");
        $this->assertSame(
            [PersistentHistoryChangeType::insert, PersistentHistoryChangeType::update],
            $this->changeTypes(),
            "and each is typed by the operation that produced it",
        );
    }

    /**
     * A batch delete records deletions. This is the change type that matters most to a peer:
     * a row it still holds is gone, and history is the only place that says so.
     *
     * @throws Exception
     */
    public function testABatchDeleteRecordsDeleteChanges(): void
    {
        $this->batchInsert([
            ["sku" => "H-1", "qty" => 1],
            ["sku" => "H-2", "qty" => 2],
        ]);

        $fetchRequest = HistoryWidget::fetchRequest();
        $fetchRequest->predicate = Predicate::format("sku == %@", new ArrayClass(["H-1"]));
        $this->context()->execute(new BatchDeleteRequest($fetchRequest));

        $this->assertSame(
            [PersistentHistoryChangeType::insert, PersistentHistoryChangeType::insert, PersistentHistoryChangeType::delete],
            $this->changeTypes(),
            "the delete is recorded alongside the insertions that came before it",
        );
    }

    /**
     * The three operations in sequence, which is what a peer replays. The order is the
     * assertion: history is read forward from a transaction, so a batch recorded out of order
     * would have a reader apply a delete before the insert that created the row.
     *
     * @throws Exception
     */
    public function testTheThreeOperationsAreRecordedInOrder(): void
    {
        $this->batchInsert([["sku" => "H-1", "qty" => 1]]);

        $update = new BatchUpdateRequest(HistoryWidget::entity());
        $update->propertiesToUpdate = new Dictionary(["qty" => 9]);
        $this->context()->execute($update);

        $this->context()->execute(new BatchDeleteRequest(HistoryWidget::fetchRequest()));

        $this->assertSame(
            [PersistentHistoryChangeType::insert, PersistentHistoryChangeType::update, PersistentHistoryChangeType::delete],
            $this->changeTypes(),
        );

        $transactions = $this->transactions();
        $this->assertCount(3, $transactions);
        $numbers = array_map(fn(PersistentHistoryTransaction $transaction): int => $transaction->transactionNumber, $transactions);
        /** @noinspection PhpPipeOperatorCanBeUsedInspection */
        $this->assertSame(array_values(array_unique($numbers)), $numbers, "each batch gets a transaction number of its own");
    }

    /**
     * History is read forward from a known transaction, so a request made after one has been
     * seen returns only what came later. This is how a peer avoids reprocessing what it has
     * already applied.
     *
     * @throws Exception
     */
    public function testHistoryAfterATransactionExcludesIt(): void
    {
        $this->batchInsert([["sku" => "H-1", "qty" => 1]]);
        $first = $this->transactions()[0];

        $update = new BatchUpdateRequest(HistoryWidget::entity());
        $update->propertiesToUpdate = new Dictionary(["qty" => 7]);
        $this->context()->execute($update);

        $request = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction($first);
        $request->resultType = PersistentHistoryResultType::transactionsAndChanges;
        $result = $this->context()->execute($request);
        $this->assertInstanceOf(PersistentHistoryResult::class, $result);
        $this->assertInstanceOf(ArrayClass::class, $result->result);

        $this->assertSame(1, $result->result->count, "only the update is newer than the insert");
    }
}
