<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;

/**
 * @property string $label
 * @property int $n
 */
final class Row extends ManagedObject
{
}

/**
 * Characterization + behavior tests for the FetchRequest execution path through an
 * XMLObjectStore: predicates, sort descriptors, fetch limit and offset.
 *
 * Some of these tests pin down behavior that is currently WRONG on purpose, so the
 * suite is an honest record of what the store does today and will fail loudly (rather
 * than silently drift) once the underlying bugs are fixed. Each such test says so and
 * names the tracked issue. Do not "fix" these assertions to the intuitive expectation
 * without also fixing the code they describe.
 */
final class FetchRequestTest extends TestCase
{
    private string $storePath;
    private ManagedObjectContext $context;

    private static function makeModel(): ManagedObjectModel
    {
        $n = new AttributeDescription();
        $n->name = "n";
        $n->type = AttributeType::integer32;

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $row = new EntityDescription();
        $row->name = "Row";
        $row->managedObjectClassName = Row::class;
        $row->properties = new ArrayClass([$n, $label]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$row]);
        return $model;
    }

    /** @return list<int> */
    private static function order(ArrayClass $result): array
    {
        return array_map(static fn(Row $row): int => $row->n, iterator_to_array($result));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-fetch-test-" . uniqid("", true) . ".xml";
        $storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));

        $coordinator = new PersistentStoreCoordinator(self::makeModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $storeURL);
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;

        // Insert five rows out of natural order so sort/limit effects are observable.
        foreach ([5, 3, 1, 4, 2] as $value) {
            $row = new Row($this->context);
            $row->n = $value;
            $row->label = "row{$value}";
        }
        $this->context->save();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    public function testFetchReturnsAllRows(): void
    {
        $this->assertCount(5, $this->context->fetch(Row::fetchRequest()));
    }

    public function testPredicateEqualityNarrowsTheResult(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n == %d", new ArrayClass([3]));
        $result = $this->context->fetch($request);

        $this->assertCount(1, $result);
        $this->assertSame(3, $result->first()->n);
    }

    public function testComparisonPredicateFiltersARange(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n > %d", new ArrayClass([3]));

        $values = self::order($this->context->fetch($request));
        sort($values);
        $this->assertSame([4, 5], $values, "n > 3 matches exactly 4 and 5");
    }

    public function testPredicateMatchingNothingReturnsEmpty(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n == %d", new ArrayClass([99]));

        $this->assertCount(0, $this->context->fetch($request));
    }

    public function testSortAscendingOrdersLowToHigh(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);

        $this->assertSame([1, 2, 3, 4, 5], self::order($this->context->fetch($request)), "an ascending sort descriptor orders the fetch low to high");
    }

    public function testSortDescendingOrdersHighToLow(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", false)]);

        $this->assertSame([5, 4, 3, 2, 1], self::order($this->context->fetch($request)), "a descending sort descriptor orders the fetch high to low");
    }

    /**
     * Sorting is a permutation of the full result regardless of direction.
     */
    public function testSortIsAPermutationOfTheFullResult(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);

        $values = self::order($this->context->fetch($request));
        sort($values);
        $this->assertSame([1, 2, 3, 4, 5], $values, "every row is still present after sorting");
    }

    public function testFetchLimitCapsTheResultCount(): void
    {
        $request = Row::fetchRequest();
        $request->fetchLimit = 2;

        $this->assertCount(2, $this->context->fetch($request), "fetchLimit caps the number of rows returned");
    }

    public function testFetchLimitOfZeroMeansNoLimit(): void
    {
        $request = Row::fetchRequest();
        $request->fetchLimit = 0;

        $this->assertCount(5, $this->context->fetch($request), "a fetchLimit of 0 is treated as no limit");
    }

    public function testFetchLimitWithSortReturnsTheFirstRowsInOrder(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);
        $request->fetchLimit = 2;

        $this->assertSame([1, 2], self::order($this->context->fetch($request)), "the limit takes the first rows after sorting");
    }

    public function testFetchOffsetSkipsLeadingRows(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);
        $request->fetchOffset = 2;

        $this->assertSame([3, 4, 5], self::order($this->context->fetch($request)), "the offset skips the leading rows after sorting");
    }

    public function testFetchOffsetAndLimitSelectAWindow(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);
        $request->fetchOffset = 1;
        $request->fetchLimit = 2;

        $this->assertSame([2, 3], self::order($this->context->fetch($request)), "offset then limit selects a subrange (offset applied first)");
    }

    public function testFetchOffsetPastTheEndReturnsEmpty(): void
    {
        $request = Row::fetchRequest();
        $request->fetchOffset = 10;

        $this->assertCount(0, $this->context->fetch($request), "an offset past the end returns no rows");
    }

    public function testCountMatchesFetchedRowCount(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n <= %d", new ArrayClass([3]));

        $this->assertCount(3, $this->context->fetch($request), "n <= 3 matches 1, 2, 3");
    }
}
