<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentHistoryChangeRequest;
use Sabatier\CoreData\PersistentHistoryChangeType;
use Sabatier\CoreData\PersistentHistoryResult;
use Sabatier\CoreData\PersistentHistoryResultType;
use Sabatier\CoreData\PersistentHistoryTransaction;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;
use const Sabatier\CoreData\PersistentHistoryTrackingKey;
use const Sabatier\CoreData\PersistentStoreIDOption;

/**
 * @property string|null $label
 * @property int $quantity
 */
final class PersistentHistoryTrackedItem extends ManagedObject
{
}

/**
 * Exercises PersistentHistoryTransaction through the real SQL history pipeline: a context save
 * writes the history tables, a PersistentHistoryChangeRequest reads them, and the SQL request
 * context reconstructs transactions, changes, and store-bound object IDs.
 */
final class PersistentHistoryTransactionSQLTest extends SQLMigrationTestCase
{
    private const string StoreID = "persistent-history-transaction-test-store";

    private ?ManagedObjectContext $historyContext = null;
    private ?PersistentStore $historyStore = null;
    private ?EntityDescription $historyEntity = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $model = self::model();
        $this->historyEntity = $model->entitiesByName["HistoryItem"];
        $coordinator = new PersistentStoreCoordinator($model);
        $this->historyStore = $coordinator->addPersistentStoreWithType(
            PersistentStoreType::sql,
            null,
            $this->storeURL,
            new Dictionary([
                PersistentHistoryTrackingKey => true,
                PersistentStoreIDOption => self::StoreID,
            ]),
        );
        $this->historyContext = new ManagedObjectContext();
        $this->historyContext->persistentStoreCoordinator = $coordinator;
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->historyContext) {
            $this->historyContext->persistentStoreCoordinator = null;
        }
        $this->historyContext = null;
        $this->historyStore = null;
        $this->historyEntity = null;
        gc_collect_cycles();

        parent::tearDown();
    }

    private static function attribute(string $name, AttributeType $type, bool $preservesValueInHistoryOnDeletion = false): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        $attribute->preservesValueInHistoryOnDeletion = $preservesValueInHistoryOnDeletion;
        return $attribute;
    }

    private static function model(): ManagedObjectModel
    {
        $entity = new EntityDescription();
        // Keep the logical model name representative of production entities. The archived object ID is stored in a TINYBLOB; using this test class's intentionally verbose name as the entity name would turn this into an unrelated storage-capacity test.
        $entity->name = "HistoryItem";
        $entity->managedObjectClassName = PersistentHistoryTrackedItem::class;
        $entity->properties = new ArrayClass([
            self::attribute("label", AttributeType::string, true),
            self::attribute("quantity", AttributeType::integer32),
        ]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private function context(): ManagedObjectContext
    {
        return $this->historyContext ?? self::fail("history context was not initialized");
    }

    private function store(): PersistentStore
    {
        return $this->historyStore ?? self::fail("history store was not initialized");
    }

    private function entity(): EntityDescription
    {
        return $this->historyEntity ?? self::fail("history entity was not initialized");
    }

    private function saveAs(string $contextName, string $author): void
    {
        $context = $this->context();
        $context->name = $contextName;
        $context->transactionAuthor = $author;
        $context->save();
    }

    /**
     * @return ArrayClass<PersistentHistoryTransaction>
     */
    private function transactionsAfter(?PersistentHistoryTransaction $transaction = null): ArrayClass
    {
        $request = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction($transaction);
        $request->resultType = PersistentHistoryResultType::transactionsAndChanges;
        $result = $this->context()->execute($request);

        self::assertInstanceOf(PersistentHistoryResult::class, $result);
        self::assertInstanceOf(ArrayClass::class, $result->result);
        /** @var ArrayClass<PersistentHistoryTransaction> */
        return $result->result;
    }

    /**
     * @param ArrayClass<PersistentHistoryTransaction> $transactions
     * @return list<PersistentHistoryTransaction>
     */
    private static function orderedTransactions(ArrayClass $transactions): array
    {
        /** @var list<PersistentHistoryTransaction> $ordered */
        $ordered = array_values($transactions->array);
        usort($ordered, fn(PersistentHistoryTransaction $left, PersistentHistoryTransaction $right): int => $left->transactionNumber <=> $right->transactionNumber);
        return $ordered;
    }

    public function testSavesRoundTripTransactionMetadataAndAllChangeTypes(): void
    {
        $context = $this->context();
        $item = new PersistentHistoryTrackedItem($context);
        $item->label = "kept in the tombstone";
        $item->quantity = 1;
        $this->saveAs("insert-context", "insert-author");
        $referenceObject = $item->objectID->referenceObject;

        $item->quantity = 2;
        $this->saveAs("update-context", "update-author");

        $context->delete($item);
        $this->saveAs("delete-context", "delete-author");

        $transactions = self::orderedTransactions($this->transactionsAfter());
        $this->assertCount(3, $transactions);
        $this->assertSame(["insert-author", "update-author", "delete-author"], array_map(fn(PersistentHistoryTransaction $transaction): ?string => $transaction->author, $transactions));
        $this->assertSame(["insert-context", "update-context", "delete-context"], array_map(fn(PersistentHistoryTransaction $transaction): ?string => $transaction->contextName, $transactions));

        $changeTypes = [];
        foreach ($transactions as $transaction) {
            $this->assertSame(self::StoreID, $transaction->storeID);
            $this->assertNotSame("", $transaction->bundleID);
            $this->assertNotSame("", $transaction->processID);
            $this->assertInstanceOf(Date::class, $transaction->timestamp);
            $this->assertNotNull($transaction->changes);
            $this->assertCount(1, $transaction->changes);

            $change = $transaction->changes->first;
            $changeTypes[] = $change->changeType;
            $this->assertSame($referenceObject, $change->changedObjectID->referenceObject);
            $this->assertSame($this->entity(), $change->changedObjectID->entity);
            $this->assertSame($this->store(), $change->changedObjectID->persistentStore);
        }
        $this->assertSame([
            PersistentHistoryChangeType::insert,
            PersistentHistoryChangeType::update,
            PersistentHistoryChangeType::delete,
        ], $changeTypes);

        $insert = $transactions[0]->changes?->first;
        $this->assertNull($insert?->updatedProperties);
        $this->assertNull($insert?->tombstone);

        $update = $transactions[1]->changes?->first;
        $updatedPropertyNames = $update?->updatedProperties?->map(fn($property): string => $property->name)->array;
        $this->assertSame(["quantity"], array_values($updatedPropertyNames ?? []));
        $this->assertNull($update?->tombstone);

        $delete = $transactions[2]->changes?->first;
        $this->assertNull($delete?->updatedProperties);
        $tombstone = $delete?->tombstone;
        $this->assertNotNull($tombstone);
        $this->assertSame("kept in the tombstone", $tombstone["label"]);
        $this->assertFalse($tombstone->offsetExists("quantity"), "only attributes marked for history preservation enter the tombstone");
    }

    public function testFetchingAfterATransactionTokenReturnsOnlyNewerTransactions(): void
    {
        $item = new PersistentHistoryTrackedItem($this->context());
        $item->label = "first";
        $item->quantity = 1;
        $this->saveAs("first-context", "first-author");
        $first = $this->transactionsAfter()->first;
        $this->assertInstanceOf(PersistentHistoryTransaction::class, $first);

        $item->quantity = 2;
        $this->saveAs("second-context", "second-author");

        $request = PersistentHistoryChangeRequest::fetchHistoryAfterToken($first->token);
        $request->resultType = PersistentHistoryResultType::transactionsAndChanges;
        $result = $this->context()->execute($request);

        $this->assertInstanceOf(PersistentHistoryResult::class, $result);
        $this->assertInstanceOf(ArrayClass::class, $result->result);
        $this->assertCount(1, $result->result);
        $this->assertGreaterThan($first->transactionNumber, $result->result->first->transactionNumber);
        $this->assertSame("second-author", $result->result->first->author);
    }

    public function testObjectIDAndTransactionOnlyResultsRetainTheirExpectedShapes(): void
    {
        $item = new PersistentHistoryTrackedItem($this->context());
        $item->label = "shape";
        $item->quantity = 7;
        $this->saveAs("shape-context", "shape-author");

        $objectIDRequest = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(null);
        $objectIDRequest->resultType = PersistentHistoryResultType::objectIDs;
        $objectIDResult = $this->context()->execute($objectIDRequest);

        $this->assertInstanceOf(PersistentHistoryResult::class, $objectIDResult);
        $this->assertInstanceOf(ArrayClass::class, $objectIDResult->result);
        $this->assertCount(1, $objectIDResult->result);
        $objectID = $objectIDResult->result->first;
        $this->assertInstanceOf(ManagedObjectID::class, $objectID);
        $this->assertSame($item->objectID->referenceObject, $objectID->referenceObject);
        $this->assertSame($this->entity(), $objectID->entity);
        $this->assertSame($this->store(), $objectID->persistentStore);

        $transactionRequest = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(null);
        $transactionRequest->resultType = PersistentHistoryResultType::transactionsOnly;
        $transactionResult = $this->context()->execute($transactionRequest);

        $this->assertInstanceOf(PersistentHistoryResult::class, $transactionResult);
        $this->assertInstanceOf(ArrayClass::class, $transactionResult->result);
        $this->assertCount(1, $transactionResult->result);
        $this->assertNull($transactionResult->result->first->changes);
        $this->assertSame("shape-author", $transactionResult->result->first->author);
    }
}
