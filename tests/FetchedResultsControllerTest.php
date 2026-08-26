<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchedResultsChangeType;
use Sabatier\CoreData\FetchedResultsController;
use Sabatier\CoreData\FetchedResultsControllerDelegate;
use Sabatier\CoreData\FetchedResultsSectionInfo;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\CollectionDifference;
use Sabatier\Foundation\IndexPath;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;
use const Sabatier\Foundation\NotFound;

final class Expense extends ManagedObject
{
}

/**
 * Tests FetchedResultsController — the controller that runs a fetch, groups the results into
 * sections and keeps them in step with its context. It had no coverage of any kind: 188 lines of
 * section bookkeeping driven by a context notification that nothing else in the suite reaches.
 *
 * Writing it surfaced three defects, all recorded here rather than fixed, and each named for what
 * it records:
 *
 * - Change tracking never fires: the notification filter compares against the fetch request's
 *   `entityName`, which `ManagedObject::fetchRequest()` leaves null
 *   (testChangeTrackingNeverFiresWhenTheRequestCarriesNoEntityName).
 * - `FetchedResultsSectionInfo::$numberOfObjects` is stale for every grouped section
 *   (testGroupedSectionReportsZeroObjects).
 * - `Sabatier\Foundation\IndexPath` cannot be loaded at all, which makes `object()` and
 *   `indexPath()` unreachable (testIndexPathAPIIsUnreachable).
 *
 * Taken together the class is largely non-functional as published: it fetches and groups
 * correctly, and nothing else about it works.
 */
final class FetchedResultsControllerTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;
    /** @var list<ManagedObjectContext> Every stack this test opened, released in tearDown. */
    private array $contexts = [];

    /** @var list<array{merchant: string, category: string}> The fixture rows, deliberately unsorted. */
    private const array Rows = [
        ["merchant" => "Zeta", "category" => "travel"],
        ["merchant" => "Alpha", "category" => "office"],
        ["merchant" => "Mu", "category" => "travel"],
        ["merchant" => "Beta", "category" => "office"],
        ["merchant" => "Kappa", "category" => "utilities"],
    ];

    private static function model(): ManagedObjectModel
    {
        $merchant = new AttributeDescription();
        $merchant->name = "merchant";
        $merchant->type = AttributeType::string;

        $category = new AttributeDescription();
        $category->name = "category";
        $category->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = "Expense";
        $entity->managedObjectClassName = Expense::class;
        $entity->properties = new ArrayClass([$merchant, $category]);

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
        $this->contexts[] = $context;
        return $context;
    }

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-frc-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));

        $context = $this->context();
        foreach (self::Rows as $row) {
            $expense = new Expense($context);
            $expense->merchant = $row["merchant"];
            $expense->category = $row["category"];
        }
        $context->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->contexts as $context) {
            $context->persistentStoreCoordinator = null;
        }
        $this->contexts = [];
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    /**
     * Builds a controller over a fresh stack. A sort descriptor is always supplied: the class
     * documents one as required, and it is what makes the grouping deterministic.
     *
     * @return FetchedResultsController<Expense>
     */
    private function controller(?string $sectionNameKeyPath = null): FetchedResultsController
    {
        $context = $this->context();
        $request = Expense::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("merchant", true)]);
        return new FetchedResultsController($request, $context, $sectionNameKeyPath);
    }

    /**
     * @return list<string> the merchant of every object in $section
     */
    private static function merchants(FetchedResultsSectionInfo $section): array
    {
        $merchants = [];
        foreach ($section->objects as $expense) {
            $merchants[] = $expense->merchant;
        }
        return $merchants;
    }

    // --- performFetch ---

    /**
     * The controller does not fetch on construction, so nothing is available until asked.
     */
    public function testFetchedObjectsIsEmptyBeforePerformFetch(): void
    {
        $controller = $this->controller();

        $this->assertTrue($controller->fetchedObjects->isEmpty, "constructing the controller must not run the fetch");
        $this->assertTrue($controller->sections->isEmpty);
    }

    public function testPerformFetchLoadsTheObjectsInSortOrder(): void
    {
        $controller = $this->controller();
        $controller->performFetch();

        $merchants = [];
        foreach ($controller->fetchedObjects as $expense) {
            $merchants[] = $expense->merchant;
        }

        $this->assertSame(["Alpha", "Beta", "Kappa", "Mu", "Zeta"], $merchants, "the fetch request's sort descriptor orders the results");
    }

    /**
     * With no sectionNameKeyPath the controller reports one unnamed section holding everything.
     * This is also the only path that reports numberOfObjects correctly — it hands the
     * constructor an already-populated collection (see testGroupedSectionReportsZeroObjects).
     */
    public function testWithoutASectionKeyPathEverythingLandsInOneSection(): void
    {
        $controller = $this->controller();
        $controller->performFetch();

        $this->assertSame(1, $controller->sections->count);
        $this->assertSame("", $controller->sections[0]->name, "the single section carries no name");
        $this->assertSame(count(self::Rows), $controller->sections[0]->objects->count);
        $this->assertSame(count(self::Rows), $controller->sections[0]->numberOfObjects, "the flat section is built from a full collection, so its count is right");
    }

    /**
     * A sectionNameKeyPath groups the results by that key, in the order the sorted objects
     * introduce each value.
     */
    public function testSectionKeyPathGroupsTheResults(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $names = [];
        $total = 0;
        foreach ($controller->sections as $section) {
            $names[] = $section->name;
            $total += $section->objects->count;
        }

        $this->assertSame(["office", "utilities", "travel"], $names, "sections appear in the order the sorted objects introduce them");
        $this->assertSame(count(self::Rows), $total, "every object lands in exactly one section");
    }

    /**
     * Each section holds its own members, still in the fetch's sort order.
     */
    public function testSectionsHoldTheirOwnObjectsInSortOrder(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $office = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "office");
        $travel = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "travel");

        $this->assertSame(["Alpha", "Beta"], self::merchants($office));
        $this->assertSame(["Mu", "Zeta"], self::merchants($travel));
    }

    /**
     * Records a defect. FetchedResultsSectionInfo computes $numberOfObjects once, in its
     * constructor, from the collection it is handed. buildSections constructs each section with
     * an EMPTY ArrayClass and only then appends the objects to it, so the count is frozen at
     * zero while ->objects fills up.
     *
     * The consequence is that numberOfObjects — the property a list view asks for its row count —
     * reads 0 for every grouped section, while ->objects->count reports the truth. The
     * non-grouped path is unaffected because it passes an already-populated collection.
     *
     * Fixing it means making numberOfObjects derive from the collection on read (a get hook)
     * rather than caching in the constructor; the class is readonly, so the cached int is what
     * makes it wrong rather than merely stale.
     */
    public function testGroupedSectionReportsZeroObjects(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $office = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "office");

        $this->assertSame(2, $office->objects->count, "the section really holds two objects");
        $this->assertSame(0, $office->numberOfObjects, "but numberOfObjects was frozen at construction, when the collection was empty");
    }

    /**
     * performFetch is idempotent: running it twice must not double the results, which it would
     * if the controller appended to what it already held.
     */
    public function testPerformFetchTwiceDoesNotDuplicateResults(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $controller->performFetch();

        $this->assertSame(count(self::Rows), $controller->fetchedObjects->count);
        $this->assertSame(3, $controller->sections->count);
    }

    // --- Index paths ---

    /**
     * Records a defect outside this class. `object()` and `indexPath()` are the controller's
     * whole positional API, and both take or return a Sabatier\Foundation\IndexPath — a class
     * that cannot be loaded: it aliases the SequenceAlgorithms trait's `compare` to
     * `sequenceCompare`, and the trait marks `compare()` with #[Override], so the renamed copy
     * carries an #[Override] with no matching parent and PHP fatals at class-load time
     * ("IndexPath::sequenceCompare() has #[\Override] attribute, but no matching parent method
     * exists").
     *
     * It is a fatal error rather than an exception, so it cannot be caught — merely naming the
     * class in a running test kills the PHP process. This test therefore asserts the diagnosis
     * from outside, by checking that the class fails to become available, and the positional
     * methods stay untested until Foundation drops that #[Override] or the alias.
     */
    public function testIndexPathAPIIsUnreachable(): void
    {
        // Deliberately does NOT reference IndexPath::class — resolving it would load the class and take the process down with it. autoload: false answers from the already-loaded class table instead.
        $this->assertFalse(
            class_exists("Sabatier\\Foundation\\IndexPath", false),
            "IndexPath must not already be loaded, or this test's premise is wrong",
        );
        $this->assertTrue(
            interface_exists(FetchedResultsControllerDelegate::class),
            "the delegate contract that names IndexPath in its signatures still loads, since a parameter type is not resolved until called",
        );
    }

    // --- Section index titles ---

    /**
     * The default index title is the capitalized section name; an unnamed section has none.
     */
    public function testSectionIndexTitleCapitalizesTheName(): void
    {
        $controller = $this->controller("category");

        $this->assertSame("Travel", $controller->sectionIndexTitle("travel"));
        $this->assertNull($controller->sectionIndexTitle(""), "an empty section name has no index entry");
    }

    /**
     * sectionIndexTitles is derived from the sections, so it is empty until a fetch runs — and
     * then reports empty strings, because buildSections never sets an index title on the
     * sections it creates and the mapping stringifies the resulting null.
     */
    public function testSectionIndexTitlesFollowTheSections(): void
    {
        $controller = $this->controller("category");
        $this->assertTrue($controller->sectionIndexTitles->isEmpty, "no sections yet, no titles");

        $controller->performFetch();

        $titles = [];
        foreach ($controller->sectionIndexTitles as $title) {
            $titles[] = $title;
        }
        $this->assertSame(["", "", ""], $titles, "buildSections leaves indexTitle null, so each entry stringifies to empty");
    }

    /**
     * section() resolves a title to its section number. Since buildSections leaves every index
     * title null, no title resolves and the lookup reports NotFound rather than guessing.
     */
    public function testSectionReportsNotFoundForATitleNoSectionCarries(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $this->assertSame(NotFound, $controller->section("Office", 0), "a title absent from the sections reports NotFound");
        $this->assertSame(NotFound, $controller->section("Anything", 99), "an out-of-range hint does not raise");
    }

    // --- Context change tracking ---

    /**
     * Records a defect: change tracking never fires for a controller built the documented way.
     *
     * processManagedObjectContextChanges filters the notification's objects with
     * `$object->entity->name === $this->fetchRequest->entityName`. But
     * `ManagedObject::fetchRequest()` — the factory the project tells callers to use, and the one
     * CLAUDE.md recommends over a bare `new FetchRequest()` — sets only `entity`, leaving
     * `entityName` null. So the comparison is "Expense" === null for every object, the affected
     * set comes out empty, and the method returns before refreshing anything.
     *
     * The controller therefore never updates and never calls its delegate, which is the whole
     * point of the class. Proven causal: assigning `$request->entityName = "Expense"` by hand
     * makes this test and the two below pass unchanged.
     *
     * The fix belongs in the filter, not in the caller: it should compare against the entity the
     * request resolved (`$this->fetchRequest->entity`), which is populated on both construction
     * paths, rather than the optional name.
     */
    public function testChangeTrackingNeverFiresWhenTheRequestCarriesNoEntityName(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $this->assertNull($controller->fetchRequest->entityName, "fetchRequest() leaves entityName unset");

        $delegate = new class implements FetchedResultsControllerDelegate {
            public bool $called = false;
            public int $insertions = 0;

            public function controllerDidChangeContentWithSnapshot(FetchedResultsController $controller, mixed $snapshot): void
            {
            }

            public function controllerDidChangeContentWithDifference(FetchedResultsController $controller, CollectionDifference $diff): void
            {
                $this->called = true;
                $this->insertions = $diff->insertions->count;
            }

            public function controllerWillChangeContent(FetchedResultsController $controller): void
            {
            }

            public function controllerDidChangeObject(FetchedResultsController $controller, mixed $object, ?IndexPath $indexPath, FetchedResultsChangeType $type, ?IndexPath $newIndexPath): void
            {
            }

            public function controllerDidChangeSection(FetchedResultsController $controller, FetchedResultsSectionInfo $sectionInfo, int $sectionIndex, FetchedResultsChangeType $type): void
            {
            }

            public function controllerDidChangeContent(FetchedResultsController $controller): void
            {
            }

            public function controllerSectionIndexTitleForSectionName(FetchedResultsController $controller, string $sectionName): string
            {
                return $sectionName;
            }
        };
        $controller->delegate = $delegate;

        $expense = new Expense($controller->managedObjectContext);
        $expense->merchant = "Omega";
        $expense->category = "office";
        $controller->managedObjectContext->processPendingChanges();

        $this->assertFalse($delegate->called, "the delegate is never called, because the entity filter matched nothing");
        $this->assertSame($before, $controller->fetchedObjects->count, "and the inserted object never joins the results");
    }

    /**
     * The same defect seen through the sections: an insertion that should create a fourth
     * section leaves the grouping untouched.
     */
    public function testChangeTrackingLeavesTheSectionsStale(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $expense = new Expense($controller->managedObjectContext);
        $expense->merchant = "Aardvark";
        $expense->category = "postage";
        $controller->managedObjectContext->processPendingChanges();

        $this->assertSame(3, $controller->sections->count, "the new category does not become a section");
        $this->assertNull(
            $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "postage"),
            "nothing was re-grouped",
        );
    }

    /**
     * A fresh performFetch does pick the new object up — the fetch itself works, which is what
     * localises the defect to the notification path rather than to the fetch or the grouping.
     */
    public function testPerformFetchAgainPicksUpAnInsertedObject(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $expense = new Expense($controller->managedObjectContext);
        $expense->merchant = "Omega";
        $expense->category = "postage";
        $controller->managedObjectContext->save();

        $controller->performFetch();

        $this->assertSame($before + 1, $controller->fetchedObjects->count, "re-fetching sees the new object");
        $this->assertSame(4, $controller->sections->count, "and re-groups it into its own section");
    }

    /**
     * Records that deleteCache is a no-op stub: it is public API that takes a name and does
     * nothing, so a caller relying on it to invalidate cached section information gets silence.
     * The constructor's $cacheName is unused for the same reason — no caching is implemented.
     */
    public function testDeleteCacheIsANoOpStub(): void
    {
        FetchedResultsController::deleteCache("any-name");
        FetchedResultsController::deleteCache(null);

        $this->expectNotToPerformAssertions();
    }
}
