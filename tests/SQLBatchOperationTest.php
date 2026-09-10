<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\BatchDeleteRequest;
use Sabatier\CoreData\BatchDeleteRequestResultType;
use Sabatier\CoreData\BatchDeleteResult;
use Sabatier\CoreData\BatchInsertRequest;
use Sabatier\CoreData\BatchInsertRequestResultType;
use Sabatier\CoreData\BatchInsertResult;
use Sabatier\CoreData\BatchUpdateRequest;
use Sabatier\CoreData\BatchUpdateRequestResultType;
use Sabatier\CoreData\BatchUpdateResult;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;

/**
 * @property string $sku
 * @property int $qty
 */
final class BatchWidget extends ManagedObject
{
}

/**
 * End-to-end tests for the batch operations — insert, update and delete — against a real
 * MariaDB server.
 *
 * A batch request is written straight through SQLConnection. SQLCore builds the request context
 * and calls executeRequestUsingConnection, which opens its own transaction and executes the
 * statement; the object graph, change tracking and the save machinery are never involved. It
 * does still record a persistent-history transaction — executeRequestCore ends by calling
 * insertTransactionForRequestContext — so "bypasses the graph" is not the same as "leaves no
 * trace". Skipping the graph is what lets a batch insert put a million rows in the store without
 * registering a million objects, and it is also what makes these worth testing on their own: an ordinary save has the
 * context to validate, maintain inverse relationships and update snapshots, and a batch request
 * has none of it. So the questions here are the ones the graph would otherwise answer: does the
 * row actually change, does the predicate scope the change to the rows it names, and does the
 * result carry what the caller asked for.
 *
 * One consequence shows up in the generator: the temporary object it builds to coerce values and
 * pick columns is created with KVO suppressed, precisely so it does not enter change tracking —
 * which is why the column list is read from changedValues() rather than from
 * changedValuesForCurrentEvent(), the latter being empty by design on this path.
 *
 * Generation is already pinned by SQLGeneratorTest, which asserts the statement without running
 * it. This file is the other half: the three contexts each had about 14% coverage because
 * nothing executed them. Every assertion reads back through the model — a fetch, never raw SQL —
 * so what is verified is what a consumer would see.
 *
 * The three result types are the axis the tests are organised around, because each is a separate
 * branch that reads the driver differently: statusOnly reports a boolean, count reads rowCount()
 * and objectIDs drains the result sets.
 */
final class SQLBatchOperationTest extends SQLMigrationTestCase
{
    /** A single-entity model; batch operations do not involve relationships. */
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
        $widget->name = "BatchWidget";
        $widget->managedObjectClassName = BatchWidget::class;
        $widget->properties = new ArrayClass([$sku, $qty]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget]);
        return $model;
    }

    /**
     * A batch insert request over the given rows, driven by the dictionary handler the
     * framework pulls until it returns false.
     *
     * @param list<array{sku: string, qty: int}> $rows
     * @throws Exception
     */
    private function insertRequest(array $rows, BatchInsertRequestResultType $resultType): BatchInsertRequest
    {
        $queue = new ArrayClass($rows);
        return new BatchInsertRequest(
            BatchWidget::entity(),
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
            resultType: $resultType,
        );
    }

    /**
     * Every widget in the store, by sku, read back through the model.
     *
     * @return array<string, int|null>
     * @throws Exception
     */
    private function widgetsBySku(ManagedObjectContext $context): array
    {
        $request = BatchWidget::fetchRequest();
        $found = [];
        foreach ($context->fetch($request) as $widget) {
            $found[$widget->sku] = $widget->qty;
        }
        return $found;
    }

    // --- Insert ---

    /**
     * The baseline: rows handed to a batch insert reach the store and are readable through an
     * ordinary fetch, without ever having been managed objects.
     *
     * @throws Exception
     */
    public function testBatchInsertWritesRowsThatAFetchCanRead(): void
    {
        $context = $this->bootstrap(self::model());
        $request = $this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 2],
        ], BatchInsertRequestResultType::statusOnly);

        $context->execute($request);

        $this->assertSame(["W-1" => 1, "W-2" => 2], $this->widgetsBySku($this->freshContext(self::model())));
    }

    /**
     * The count result type reports how many rows the statement affected, which is the cheap
     * answer a caller wants when the rows themselves are not needed.
     *
     * @throws Exception
     */
    public function testBatchInsertCountReportsTheNumberOfRows(): void
    {
        $context = $this->bootstrap(self::model());
        $request = $this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 2],
            ["sku" => "W-3", "qty" => 3],
        ], BatchInsertRequestResultType::count);

        /** @var BatchInsertResult $result */
        $result = $context->execute($request);

        $this->assertSame(3, (int)(string)$result->result);
    }

    /**
     * The objectIDs result type drains the statement's result sets and hands back identifiers
     * for what was written. They must be real: fetching one has to return the row it names.
     *
     * @throws Exception
     */
    public function testBatchInsertObjectIDsIdentifyTheInsertedRows(): void
    {
        $context = $this->bootstrap(self::model());
        $request = $this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 2],
        ], BatchInsertRequestResultType::objectIDs);

        /** @var BatchInsertResult $result */
        $result = $context->execute($request);
        $objectIDs = $result->result;

        // $result->result is typed by the result type that was asked for; objectIDs makes it a
        // collection, and narrowing it here is what lets the assertions below read it as one.
        $this->assertInstanceOf(ArrayClass::class, $objectIDs);
        $this->assertSame(2, $objectIDs->count, "one identifier per inserted row");
        $readContext = $this->freshContext(self::model());
        foreach ($objectIDs as $objectID) {
            $this->assertInstanceOf(ManagedObjectID::class, $objectID);
            $widget = $readContext->existingObject($objectID);
            $this->assertNotNull($widget, "the identifier names a row that is really in the store");
            $this->assertContains($widget->valueForKey("sku"), ["W-1", "W-2"], "and it is one of the rows just inserted");
        }
    }

    // --- Update ---

    /**
     * A batch update rewrites the rows its predicate selects, and only those. The untouched row
     * is the assertion that matters: an update whose predicate is dropped silently rewrites the
     * whole table.
     *
     * @throws Exception
     */
    public function testBatchUpdateChangesOnlyTheRowsThePredicateSelects(): void
    {
        $context = $this->bootstrap(self::model());
        $context->execute($this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 2],
        ], BatchInsertRequestResultType::statusOnly));

        $updateContext = $this->freshContext(self::model());
        $request = new BatchUpdateRequest(BatchWidget::entity());
        $request->predicate = Predicate::format("sku == %@", new ArrayClass(["W-1"]));
        $request->propertiesToUpdate = new Dictionary(["qty" => 99]);
        $updateContext->execute($request);

        $this->assertSame(["W-1" => 99, "W-2" => 2], $this->widgetsBySku($this->freshContext(self::model())));
    }

    /**
     * The count result type of an update reports rows affected, which is how a caller learns the
     * predicate matched anything at all.
     *
     * @throws Exception
     */
    public function testBatchUpdateCountReportsAffectedRows(): void
    {
        $context = $this->bootstrap(self::model());
        $context->execute($this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 1],
            ["sku" => "W-3", "qty" => 5],
        ], BatchInsertRequestResultType::statusOnly));

        $updateContext = $this->freshContext(self::model());
        $request = new BatchUpdateRequest(BatchWidget::entity());
        $request->predicate = Predicate::format("qty == %d", new ArrayClass(["1"]));
        $request->propertiesToUpdate = new Dictionary(["qty" => 7]);
        $request->resultType = BatchUpdateRequestResultType::count;
        /** @var BatchUpdateResult $result */
        $result = $updateContext->execute($request);

        $this->assertSame(2, (int)(string)$result->result, "both matching rows were counted");
    }

    /**
     * An update whose predicate matches nothing is not an error: it affects no rows and leaves
     * the store as it was.
     *
     * @throws Exception
     */
    public function testABatchUpdateMatchingNothingChangesNothing(): void
    {
        $context = $this->bootstrap(self::model());
        $context->execute($this->insertRequest([["sku" => "W-1", "qty" => 1]], BatchInsertRequestResultType::statusOnly));

        $updateContext = $this->freshContext(self::model());
        $request = new BatchUpdateRequest(BatchWidget::entity());
        $request->predicate = Predicate::format("sku == %@", new ArrayClass(["absent"]));
        $request->propertiesToUpdate = new Dictionary(["qty" => 99]);
        $request->resultType = BatchUpdateRequestResultType::count;
        /** @var BatchUpdateResult $result */
        $result = $updateContext->execute($request);

        $this->assertSame(0, (int)(string)$result->result);
        $this->assertSame(["W-1" => 1], $this->widgetsBySku($this->freshContext(self::model())));
    }

    // --- Delete ---

    /**
     * A batch delete removes the rows its fetch request selects, and leaves the rest. The delete
     * runs its selection FOR UPDATE first, which is why this is only meaningful end to end.
     *
     * @throws Exception
     */
    public function testBatchDeleteRemovesOnlyTheSelectedRows(): void
    {
        $context = $this->bootstrap(self::model());
        $context->execute($this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 2],
            ["sku" => "W-3", "qty" => 3],
        ], BatchInsertRequestResultType::statusOnly));

        $deleteContext = $this->freshContext(self::model());
        $fetchRequest = BatchWidget::fetchRequest();
        $fetchRequest->predicate = Predicate::format("qty > %d", new ArrayClass(["1"]));
        $request = new BatchDeleteRequest($fetchRequest);
        $request->resultType = BatchDeleteRequestResultType::statusOnly;
        $deleteContext->execute($request);

        $this->assertSame(["W-1" => 1], $this->widgetsBySku($this->freshContext(self::model())), "only the rows the predicate did not select survive");
    }

    /**
     * The count result type of a delete reports how many rows went away.
     *
     * @throws Exception
     */
    public function testBatchDeleteCountReportsDeletedRows(): void
    {
        $context = $this->bootstrap(self::model());
        $context->execute($this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 2],
            ["sku" => "W-3", "qty" => 3],
        ], BatchInsertRequestResultType::statusOnly));

        $deleteContext = $this->freshContext(self::model());
        $fetchRequest = BatchWidget::fetchRequest();
        $fetchRequest->predicate = Predicate::format("qty > %d", new ArrayClass(["1"]));
        $request = new BatchDeleteRequest($fetchRequest);
        $request->resultType = BatchDeleteRequestResultType::count;

        /** @var BatchDeleteResult $result */
        $result = $deleteContext->execute($request);

        $this->assertSame(2, (int)(string)$result->result);
    }

    /**
     * A delete with no predicate empties the entity. Worth pinning deliberately rather than
     * discovering: it is both a legitimate operation and the shape a dropped predicate takes.
     *
     * @throws Exception
     */
    public function testABatchDeleteWithoutAPredicateEmptiesTheEntity(): void
    {
        $context = $this->bootstrap(self::model());
        $context->execute($this->insertRequest([
            ["sku" => "W-1", "qty" => 1],
            ["sku" => "W-2", "qty" => 2],
        ], BatchInsertRequestResultType::statusOnly));

        $deleteContext = $this->freshContext(self::model());
        $deleteContext->execute(new BatchDeleteRequest(BatchWidget::fetchRequest()));

        $this->assertSame([], $this->widgetsBySku($this->freshContext(self::model())));
    }

    /**
     * Because the write never reaches the graph, an object a context already holds keeps the
     * value it was loaded with; only a refetch sees the new one. That is the design rather than
     * a limitation, and pinning it stops the behaviour being read as a bug later.
     *
     * @throws Exception
     */
    public function testAnExistingContextDoesNotSeeABatchUpdateUntilItRefetches(): void
    {
        $context = $this->bootstrap(self::model());
        $context->execute($this->insertRequest([["sku" => "W-1", "qty" => 1]], BatchInsertRequestResultType::statusOnly));

        $readContext = $this->freshContext(self::model());
        $widget = $readContext->fetch(BatchWidget::fetchRequest())->first;
        $this->assertNotNull($widget);
        $this->assertSame(1, $widget->qty);

        $updateContext = $this->freshContext(self::model());
        $request = new BatchUpdateRequest(BatchWidget::entity());
        $request->propertiesToUpdate = new Dictionary(["qty" => 42]);
        $updateContext->execute($request);

        $this->assertSame(1, $widget->valueForKey("qty"), "the already-materialized object keeps the value it was loaded with");
        $this->assertSame(["W-1" => 42], $this->widgetsBySku($this->freshContext(self::model())), "while the store really did change");
    }
}
