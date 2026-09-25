<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\FetchedPropertyDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

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
    private URL $storeURL;
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

    /**
     * A request over a Row entity that declares the given fetched property.
     *
     * The property has to be part of the entity's `properties` from the start: an
     * EntityDescription stops being editable the moment it joins a ManagedObjectModel
     * (EntityDescription::throwIfNotEditable), so it cannot be amended afterwards — not even
     * before a coordinator sees it. This builds its own model rather than touching setUp's.
     */
    private static function requestCarrying(FetchedPropertyDescription $fetchedProperty): FetchRequest
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
        $row->properties = new ArrayClass([$n, $label, $fetchedProperty]);

        /** @noinspection PhpObjectFieldsAreOnlyWrittenInspection */
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$row]);

        $request = new FetchRequest("Row");
        $request->entity = $row;
        $request->propertiesToFetch = new ArrayClass([$row->propertiesByName[$fetchedProperty->name]]);
        return $request;
    }

    /** @throws Exception */
    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");

        $coordinator = new PersistentStoreCoordinator(self::makeModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;

        // Insert five rows out of natural order so sort/limit effects are observable.
        foreach ([5, 3, 1, 4, 2] as $value) {
            $row = new Row($this->context);
            $row->n = $value;
            $row->label = "row$value";
        }
        $this->context->save();
    }

    /** @throws Exception */
    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    /** @throws Exception */
    public function testFetchReturnsAllRows(): void
    {
        $this->assertCount(5, $this->context->fetch(Row::fetchRequest()));
    }

    /** @throws Exception */
    public function testPredicateEqualityNarrowsTheResult(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n == %d", new ArrayClass([3]));
        $result = $this->context->fetch($request);

        $this->assertCount(1, $result);
        $this->assertSame(3, $result->first()->n);
    }

    /** @throws Exception */
    public function testComparisonPredicateFiltersARange(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n > %d", new ArrayClass([3]));

        $values = self::order($this->context->fetch($request));
        sort($values);
        $this->assertSame([4, 5], $values, "n > 3 matches exactly 4 and 5");
    }

    /** @throws Exception */
    public function testPredicateMatchingNothingReturnsEmpty(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n == %d", new ArrayClass([99]));

        $this->assertCount(0, $this->context->fetch($request));
    }

    /** @throws Exception */
    public function testSortAscendingOrdersLowToHigh(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);

        $this->assertSame([1, 2, 3, 4, 5], self::order($this->context->fetch($request)), "an ascending sort descriptor orders the fetch low to high");
    }

    /** @throws Exception */
    public function testSortDescendingOrdersHighToLow(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", false)]);

        $this->assertSame([5, 4, 3, 2, 1], self::order($this->context->fetch($request)), "a descending sort descriptor orders the fetch high to low");
    }

    /**
     * Sorting is a permutation of the full result regardless of direction.
     *
     * @throws Exception
     */
    public function testSortIsAPermutationOfTheFullResult(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);

        $values = self::order($this->context->fetch($request));
        sort($values);
        $this->assertSame([1, 2, 3, 4, 5], $values, "every row is still present after sorting");
    }

    /** @throws Exception */
    public function testFetchLimitCapsTheResultCount(): void
    {
        $request = Row::fetchRequest();
        $request->fetchLimit = 2;

        $this->assertCount(2, $this->context->fetch($request), "fetchLimit caps the number of rows returned");
    }

    /** @throws Exception */
    public function testFetchLimitOfZeroMeansNoLimit(): void
    {
        $request = Row::fetchRequest();
        $request->fetchLimit = 0;

        $this->assertCount(5, $this->context->fetch($request), "a fetchLimit of 0 is treated as no limit");
    }

    /** @throws Exception */
    public function testFetchLimitWithSortReturnsTheFirstRowsInOrder(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);
        $request->fetchLimit = 2;

        $this->assertSame([1, 2], self::order($this->context->fetch($request)), "the limit takes the first rows after sorting");
    }

    /** @throws Exception */
    public function testFetchOffsetSkipsLeadingRows(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);
        $request->fetchOffset = 2;

        $this->assertSame([3, 4, 5], self::order($this->context->fetch($request)), "the offset skips the leading rows after sorting");
    }

    /** @throws Exception */
    public function testFetchOffsetAndLimitSelectAWindow(): void
    {
        $request = Row::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);
        $request->fetchOffset = 1;
        $request->fetchLimit = 2;

        $this->assertSame([2, 3], self::order($this->context->fetch($request)), "offset then limit selects a subrange (offset applied first)");
    }

    /** @throws Exception */
    public function testFetchOffsetPastTheEndReturnsEmpty(): void
    {
        $request = Row::fetchRequest();
        $request->fetchOffset = 10;

        $this->assertCount(0, $this->context->fetch($request), "an offset past the end returns no rows");
    }

    /** @throws Exception */
    public function testCountMatchesFetchedRowCount(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n <= %d", new ArrayClass([3]));

        $this->assertCount(3, $this->context->fetch($request), "n <= 3 matches 1, 2, 3");
    }

    /**
     * execute() resolves its own context from the operation queue rather than taking one, so it
     * works only inside a block the context is running — performBlockAndWait is what associates
     * the two. Inside that block it agrees with an explicit fetch on the same context.
     *
     * @throws Exception
     */
    public function testExecuteRunsAgainstTheContextOfTheCurrentQueue(): void
    {
        $request = Row::fetchRequest();
        $request->predicate = Predicate::format("n <= %d", new ArrayClass([3]));
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);

        $expected = self::order($this->context->fetch($request));
        $actual = [];
        $this->context->performBlockAndWait(function () use ($request, &$actual): void {
            $actual = self::order($request->execute());
        });

        $this->assertSame($expected, $actual, "execute() and an explicit fetch on the same context return the same rows");
    }

    /**
     * Outside such a block there is no context to resolve, and execute() says so rather than
     * silently reaching for some other context. This is the trap behind the project rule that a
     * bare `new FetchRequest("Entity")` dies in tests: it is this resolution failing.
     *
     * @throws Exception
     */
    public function testExecuteWithoutAnAssociatedContextRaises(): void
    {
        $request = new FetchRequest("Row");

        $this->expectException(InternalInconsistencyException::class);
        $request->execute();
    }

    /**
     * A fetched property serializes as the shape of the entity it fetches, not of the entity it
     * hangs off — it is a stored query, so its cached shape has to describe the rows it will
     * bring back.
     *
     * The property is declared while the model is still being built: an EntityDescription
     * refuses edits once it has been initialized into a coordinator, so this uses its own stack
     * rather than amending the one setUp created.
     */
    public function testSerializationOfAFetchedPropertyDescribesItsDestinationEntity(): void
    {
        $peer = new FetchRequest("Row");
        $peer->predicate = Predicate::format("n > %d", new ArrayClass([0]));

        $fetched = new FetchedPropertyDescription();
        $fetched->name = "peers";
        $fetched->fetchRequest = $peer;

        $request = self::requestCarrying($fetched);

        /** @var Dictionary<mixed> $shape */
        $shape = $request->serialization["peers"];

        $this->assertInstanceOf(Dictionary::class, $shape, "a fetched property serializes as a nested shape");
        $this->assertSame(AttributeType::integer32, $shape["n"], "and that shape is the fetched entity's own attributes");
        $this->assertSame(AttributeType::string, $shape["label"]);
    }

    /**
     * A fetched property whose request names no entity has no shape to describe, so it
     * serializes as an empty dictionary rather than raising — the model is still loadable, the
     * property simply contributes nothing to the cached shape.
     */
    public function testSerializationOfAFetchedPropertyWithNoEntityIsEmpty(): void
    {
        $fetched = new FetchedPropertyDescription();
        $fetched->name = "unbound";
        $fetched->fetchRequest = new FetchRequest();

        $request = self::requestCarrying($fetched);

        /** @var Dictionary<mixed> $shape */
        $shape = $request->serialization["unbound"];

        $this->assertTrue($shape->isEmpty, "a fetched property with no destination entity describes nothing");
    }
}
