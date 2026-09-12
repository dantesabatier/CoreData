<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use const Sabatier\CoreData\ManagedObjectEntityNameKey;
use const Sabatier\CoreData\ManagedObjectObjectIDKey;

/**
 * @property int $amount
 * @property string $category
 * @property string $code
 */
final class AtomicLedger extends ManagedObject
{
}

/**
 * Tests AtomicStore's fetch engine, exercised through XMLObjectStore (the abstract class has no
 * instantiable form of its own, and MemoryObjectStore has no load()).
 *
 * XMLObjectStoreTest already covers the DOM-level round trip — what the store writes to the file
 * and reads back. This suite covers the part above that: `executeFetchRequest`, which is where an
 * atomic store does in PHP what the SQL store delegates to the database. Every fetch walks the
 * whole node cache and then filters, groups, sorts and paginates in memory, so each result type
 * takes a different branch through ~120 lines with no database to fall back on.
 *
 * The branches pinned here are the ones no other suite reaches: the four FetchRequestResultType
 * cases, offset/limit ordering relative to filtering and sorting, GROUP BY with a HAVING
 * predicate, propertiesToFetch projection, and the objectID-reference rewriting that lets a
 * predicate compare against a raw reference instead of a ManagedObjectID.
 */
final class AtomicStoreFetchTest extends TestCase
{
    private URL $storeURL;

    /** @var list<array{code: string, amount: int, category: string}> The fixture rows. */
    private const array Rows = [
        ["code" => "A-1", "amount" => 100, "category" => "rent"],
        ["code" => "A-2", "amount" => 200, "category" => "rent"],
        ["code" => "B-1", "amount" => 300, "category" => "wages"],
        ["code" => "B-2", "amount" => 400, "category" => "wages"],
        ["code" => "C-1", "amount" => 500, "category" => "tax"],
    ];

    /**
     * A fresh model per stack: entity descriptions freeze once bound to a coordinator, so a
     * second stack over the same file needs its own.
     */
    private static function model(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $amount = new AttributeDescription();
        $amount->name = "amount";
        $amount->type = AttributeType::integer32;

        $category = new AttributeDescription();
        $category->name = "category";
        $category->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = "AtomicLedger";
        $entity->managedObjectClassName = AtomicLedger::class;
        $entity->properties = new ArrayClass([$code, $amount, $category]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");

        $context = $this->context();
        foreach (self::Rows as $row) {
            $ledger = new AtomicLedger($context);
            $ledger->code = $row["code"];
            $ledger->amount = $row["amount"];
            $ledger->category = $row["category"];
        }
        $context->save();
    }

    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    /**
     * @return list<string> the "code" of every row the request selects, in the order returned
     */
    private function codes(callable $configure): array
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $configure($request);
        $codes = [];
        foreach ($context->fetch($request) as $object) {
            $codes[] = $object->code;
        }
        return $codes;
    }

    // --- Result types ---

    /**
     * The default result type returns managed objects, ordered by the sort descriptors.
     */
    public function testManagedObjectResultTypeReturnsSortedObjects(): void
    {
        $codes = $this->codes(function (object $request): void {
            $request->sortDescriptors = new ArrayClass([new SortDescriptor("code", true)]);
        });

        $this->assertSame(["A-1", "A-2", "B-1", "B-2", "C-1"], $codes);
    }

    /**
     * managedObjectIDResultType returns identities rather than objects — the cheap shape a
     * caller uses to check existence or to hand references to another context.
     */
    public function testObjectIDResultTypeReturnsObjectIDs(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->resultType = FetchRequestResultType::managedObjectIDResultType;
        $request->predicate = Predicate::format("code == \"A-1\"");

        $results = $context->fetch($request);

        $this->assertSame(1, $results->count);
        $this->assertInstanceOf(ManagedObjectID::class, $results->first, "the result type asks for identities, not objects");
    }

    /**
     * countResultType reports the total and short-circuits before pagination — the count is of
     * everything the predicate matches, which is what makes it usable for "how many are there".
     */
    public function testCountResultTypeReturnsTheMatchingTotal(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->resultType = FetchRequestResultType::countResultType;
        $request->predicate = Predicate::format("category == \"rent\"");

        $results = $context->fetch($request);

        $this->assertInstanceOf(Number::class, $results->first);
        $this->assertSame(2, $results->first->intValue);
    }

    /**
     * A fetchLimit must not truncate a count: the count branch returns before the offset/limit
     * clamping, so the answer stays the full total.
     */
    public function testCountResultTypeIgnoresFetchLimit(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->resultType = FetchRequestResultType::countResultType;
        $request->fetchLimit = 2;

        $this->assertSame(count(self::Rows), $context->fetch($request)->first->intValue, "a limit paginates rows, it does not cap a count");
    }

    /**
     * dictionaryResultType returns plain dictionaries instead of managed objects.
     */
    public function testDictionaryResultTypeReturnsDictionaries(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->predicate = Predicate::format("code == \"C-1\"");

        $results = $context->fetch($request);

        $this->assertSame(1, $results->count);
        $this->assertSame("C-1", $results->first["code"]);
        $this->assertSame("tax", $results->first["category"]);
    }

    /**
     * propertiesToFetch narrows a dictionary result to the requested keys; the object ID and
     * entity name are always retained, since a caller has to be able to identify the row.
     */
    public function testPropertiesToFetchProjectsOnlyTheRequestedKeys(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToFetch = new ArrayClass(["code"]);
        $request->predicate = Predicate::format("code == \"C-1\"");

        $row = $context->fetch($request)->first;

        $this->assertSame("C-1", $row["code"]);
        $this->assertTrue($row->offsetExists(ManagedObjectObjectIDKey), "the object ID survives the projection");
        $this->assertTrue($row->offsetExists(ManagedObjectEntityNameKey), "so does the entity name");
        $this->assertFalse($row->offsetExists("amount"), "a property that was not asked for is dropped");
    }

    // --- Pagination ---

    /**
     * The offset is applied after sorting, so it skips the first rows of the ORDERED result and
     * not of the node cache's arbitrary order.
     */
    public function testFetchOffsetSkipsFromTheSortedResult(): void
    {
        $codes = $this->codes(function (object $request): void {
            $request->sortDescriptors = new ArrayClass([new SortDescriptor("code", true)]);
            $request->fetchOffset = 2;
        });

        $this->assertSame(["B-1", "B-2", "C-1"], $codes);
    }

    /**
     * Offset and limit compose as a window over the sorted result.
     */
    public function testFetchOffsetAndLimitSelectAWindow(): void
    {
        $codes = $this->codes(function (object $request): void {
            $request->sortDescriptors = new ArrayClass([new SortDescriptor("code", true)]);
            $request->fetchOffset = 1;
            $request->fetchLimit = 2;
        });

        $this->assertSame(["A-2", "B-1"], $codes);
    }

    /**
     * An offset past the end yields nothing. This is the clamp the implementation calls out:
     * dropFirst() raises a range error when asked to drop more than the collection holds, so
     * the offset is clamped to the count rather than passed through.
     */
    public function testFetchOffsetBeyondTheResultYieldsNothing(): void
    {
        $codes = $this->codes(function (object $request): void {
            $request->fetchOffset = count(self::Rows) + 10;
        });

        $this->assertSame([], $codes);
    }

    /**
     * A fetchLimit of 0 means "no limit", not "no rows".
     */
    public function testZeroFetchLimitMeansUnlimited(): void
    {
        $codes = $this->codes(function (object $request): void {
            $request->fetchLimit = 0;
        });

        $this->assertCount(count(self::Rows), $codes);
    }

    /**
     * Pagination is applied after filtering, so the limit counts matching rows.
     */
    public function testFetchLimitAppliesAfterThePredicate(): void
    {
        $codes = $this->codes(function (object $request): void {
            $request->predicate = Predicate::format("category == \"wages\"");
            $request->sortDescriptors = new ArrayClass([new SortDescriptor("code", true)]);
            $request->fetchLimit = 1;
        });

        $this->assertSame(["B-1"], $codes, "the limit takes the first MATCHING row, not the first row overall");
    }

    // --- Grouping ---

    /**
     * GROUP BY is only meaningful for a dictionary result, and asking for it with any other
     * result type is a programming error the store refuses rather than silently ignores.
     */
    public function testGroupByWithoutDictionaryResultTypeIsRejected(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->propertiesToGroupBy = new ArrayClass(["category"]);

        $this->expectExceptionMessageMatches("/GROUP BY requires/");
        $context->fetch($request);
    }

    /**
     * A HAVING predicate filters whole groups. Only the "rent" group has a row under 150, so
     * that group survives and the others are dropped.
     */
    public function testHavingPredicateFiltersGroups(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToGroupBy = new ArrayClass(["category"]);
        $request->havingPredicate = Predicate::format("amount < 150");

        $categories = [];
        foreach ($context->fetch($request) as $row) {
            $categories[$row["category"]] = true;
        }

        $this->assertSame(["rent"], array_keys($categories), "only the group containing a matching row survives");
    }

    // --- Predicate object-ID references ---

    /**
     * A predicate may compare an objectID key path against a RAW reference rather than a
     * ManagedObjectID, which is what happens when the value comes from outside the graph (a URL
     * parameter, a serialized payload). resolvePredicateObjectReferences rewrites the constant
     * into a real object ID so the comparison can succeed; without that rewriting a raw
     * reference would never match.
     */
    public function testPredicateOnObjectIDAcceptsARawReference(): void
    {
        $context = $this->context();
        $idRequest = AtomicLedger::fetchRequest();
        $idRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
        $idRequest->predicate = Predicate::format("code == \"B-1\"");
        /** @var ManagedObjectID $objectID */
        $objectID = $context->fetch($idRequest)->first;
        $reference = $objectID->referenceObject;

        // The same comparison, but with the bare reference the store has to resolve itself.
        $readContext = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->predicate = Predicate::format(sprintf("%s == %s", ManagedObjectObjectIDKey, is_int($reference) ? (string)$reference : "\"$reference\""));

        $results = $readContext->fetch($request);

        $this->assertSame(1, $results->count, "the raw reference resolved to the object it identifies");
        $this->assertSame("B-1", $results->first->code);
    }

    /**
     * The rewriting recurses into compound predicates, so an objectID comparison still resolves
     * when it is ANDed with an ordinary one.
     */
    public function testObjectIDReferenceResolvesInsideACompoundPredicate(): void
    {
        $context = $this->context();
        $idRequest = AtomicLedger::fetchRequest();
        $idRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
        $idRequest->predicate = Predicate::format("code == \"B-2\"");
        /** @var ManagedObjectID $objectID */
        $objectID = $context->fetch($idRequest)->first;
        $reference = $objectID->referenceObject;

        $readContext = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->predicate = Predicate::format(sprintf(
            "(%s == %s) AND (category == \"wages\")",
            ManagedObjectObjectIDKey,
            is_int($reference) ? (string)$reference : "\"$reference\"",
        ));

        $this->assertSame(1, $readContext->fetch($request)->count, "the rewriting recurses through the compound predicate");
    }

    /**
     * A reference that identifies no row matches nothing, rather than resolving to some other
     * object or raising.
     */
    public function testUnknownObjectIDReferenceMatchesNothing(): void
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->predicate = Predicate::format(sprintf("%s == 999999", ManagedObjectObjectIDKey));

        $this->assertSame(0, $context->fetch($request)->count);
    }

    // --- Reference allocation ---

    /**
     * References are handed out monotonically and persisted in the store metadata, so a second
     * stack over the same file does not reuse a reference already taken. A collision here would
     * silently overwrite an existing row.
     */
    public function testReferencesDoNotRepeatAcrossStacks(): void
    {
        $firstPass = $this->referenceObjects();

        // A brand-new stack over the same file, inserting more rows.
        $context = $this->context();
        foreach (["D-1", "D-2"] as $code) {
            $ledger = new AtomicLedger($context);
            $ledger->code = $code;
            $ledger->amount = 600;
            $ledger->category = "misc";
        }
        $context->save();

        $allReferences = $this->referenceObjects();

        $this->assertCount(count(self::Rows) + 2, $allReferences, "both new rows were stored");
        $this->assertSame(count($allReferences), count(array_unique($allReferences)), "no reference was handed out twice");
        foreach ($firstPass as $reference) {
            $this->assertContains($reference, $allReferences, "an existing row kept its reference");
        }
    }

    /**
     * @return list<int|string> every stored row's reference object
     */
    private function referenceObjects(): array
    {
        $context = $this->context();
        $request = AtomicLedger::fetchRequest();
        $request->resultType = FetchRequestResultType::managedObjectIDResultType;
        $references = [];
        foreach ($context->fetch($request) as $objectID) {
            $references[] = $objectID->referenceObject;
        }
        return $references;
    }
}
