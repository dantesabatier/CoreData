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
use Sabatier\CoreData\PersistentHistoryChangeRequest;
use Sabatier\CoreData\PersistentHistoryResult;
use Sabatier\CoreData\PersistentHistoryResultType;
use Sabatier\CoreData\PersistentHistoryTransaction;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use const Sabatier\CoreData\PersistentHistoryTrackingKey;

/**
 * @property string $label
 */
final class PurgeItem extends ManagedObject
{
}

/**
 * Covers purging the persistent history, which nothing exercised.
 *
 * The history grows without bound as a store is written to, so a deployment has to be able to
 * discard the part it has already consumed — by transaction, by date, or wholesale. The delete
 * branch of SQLPersistentHistoryChangeRequestContext does all three and was untouched.
 *
 * What makes it more than a DELETE is the tail: after purging, a store that still holds history
 * recomputes the primary-key maximum of its history entities, while one left with nothing drops
 * the tracking tables altogether. Both halves are asserted here, because dropping the tables when
 * rows remain would lose them, and keeping empty tables around would leave the store claiming a
 * history it does not have.
 */
final class PersistentHistoryDeletionTest extends SQLMigrationTestCase
{
    private ?ManagedObjectContext $historyContext = null;

    private static function model(): ManagedObjectModel
    {
        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;
        $label->isOptional = true;

        $entity = new EntityDescription();
        $entity->name = "PurgeItem";
        $entity->managedObjectClassName = PurgeItem::class;
        $entity->properties = new ArrayClass([$label]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    /** @throws Exception */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $this->storeURL, new Dictionary([
            PersistentHistoryTrackingKey => true,
        ]));
        $this->historyContext = new ManagedObjectContext();
        $this->historyContext->persistentStoreCoordinator = $coordinator;
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->historyContext) {
            /** @noinspection PhpFieldImmediatelyRewrittenInspection */
            $this->historyContext->persistentStoreCoordinator = null;
        }
        $this->historyContext = null;
        gc_collect_cycles();
        parent::tearDown();
    }

    private function context(): ManagedObjectContext
    {
        return $this->historyContext ?? self::fail("history context was not initialized");
    }

    /**
     * Writes one object and saves, producing one history transaction.
     *
     * @throws Exception
     */
    private function write(string $label): void
    {
        $context = $this->context();
        $item = new PurgeItem($context);
        $item->label = $label;
        $context->save();
    }

    /**
     * Every transaction the history currently holds.
     *
     * @return ArrayClass<PersistentHistoryTransaction>
     * @throws Exception
     */
    private function transactions(): ArrayClass
    {
        $request = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(null);
        $request->resultType = PersistentHistoryResultType::transactionsAndChanges;
        $result = $this->context()->execute($request);
        self::assertInstanceOf(PersistentHistoryResult::class, $result);
        /** @var ArrayClass<PersistentHistoryTransaction> $transactions */
        $transactions = $result->result;
        return $transactions;
    }

    /**
     * Deleting before a transaction number discards what precedes it and keeps the rest.
     *
     * @throws Exception
     */
    public function testDeletingBeforeATransactionKeepsTheLaterOnes(): void
    {
        $this->write("first");
        $this->write("second");
        $this->write("third");

        $before = $this->transactions();
        $this->assertGreaterThan(1, $before->count, "precondition: several transactions were recorded");

        /** @var PersistentHistoryTransaction $last */
        $last = $before->last;
        $this->context()->execute(PersistentHistoryChangeRequest::deleteHistoryBeforeTransaction($last));

        $after = $this->transactions();
        $this->assertLessThan($before->count, $after->count, "the earlier transactions are discarded");
        $this->assertGreaterThan(0, $after->count, "and the store still holds the history it was told to keep");
    }

    /**
     * Deleting before a date in the future discards everything, and a store left with no history
     * drops its tracking tables rather than keeping empty ones.
     *
     * @throws Exception
     */
    public function testDeletingEverythingDropsTheTrackingTables(): void
    {
        $this->write("first");
        $this->write("second");

        $this->assertGreaterThan(0, $this->transactions()->count, "precondition: the history has rows");
        $this->assertTrue($this->tableExists("PersistentHistoryTransaction"), "precondition: the tracking tables exist");

        $this->context()->execute(PersistentHistoryChangeRequest::deleteHistoryBeforeDate(new Date()->addingTimeInterval(3600)));

        $this->assertFalse($this->tableExists("PersistentHistoryTransaction"), "a store with no history left drops the tracking tables");
    }
}
