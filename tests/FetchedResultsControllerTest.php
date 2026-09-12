<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
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
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\IndexPath;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use const Sabatier\Foundation\NotFound;

/**
 * @property string $category
 * @property string $merchant
 */
final class Expense extends ManagedObject
{
}

/** An entity with nothing to do with Expense, and no attribute in common with it. */
/**
 * @property string $note
 */
final class Reminder extends ManagedObject
{
}

/** A subentity of Expense, so a fetch on the parent legitimately covers it. */
/**
 * @property string $period
 */
final class RecurringExpense extends ManagedObject
{
}

/**
 * Tests FetchedResultsController — the controller that runs a fetch, groups the results into
 * sections and keeps them in step with its context. It had no coverage of any kind: 188 lines of
 * section bookkeeping driven by a context notification that nothing else in the suite reaches.
 *
 * Writing it surfaced five defects, all since fixed: change tracking never fired, the refresh
 * admitted objects of any entity, section row counts read zero, indexPath() missed row 0, and
 * Foundation's IndexPath could not be loaded at all.
 */
final class FetchedResultsControllerTest extends TestCase
{
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

        $period = new AttributeDescription();
        $period->name = "period";
        $period->type = AttributeType::string;

        $recurring = new EntityDescription();
        $recurring->name = "RecurringExpense";
        $recurring->managedObjectClassName = RecurringExpense::class;
        $recurring->superentity = $entity;
        $recurring->properties = new ArrayClass([$period]);
        $entity->subentities = new ArrayClass([$recurring]);

        // Deliberately shares no attribute name with Expense: sorting the results by "merchant" raises UndefinedKeyException if one of these ever leaks in.
        $note = new AttributeDescription();
        $note->name = "note";
        $note->type = AttributeType::string;

        $reminder = new EntityDescription();
        $reminder->name = "Reminder";
        $reminder->managedObjectClassName = Reminder::class;
        $reminder->properties = new ArrayClass([$note]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity, $reminder]);
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

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");

        $context = $this->context();
        foreach (self::Rows as $row) {
            $expense = new Expense($context);
            $expense->merchant = $row["merchant"];
            $expense->category = $row["category"];
        }
        $context->save();
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->contexts as $context) {
            $context->persistentStoreCoordinator = null;
        }
        $this->contexts = [];
        FileManager::default()->removeItem($this->storeURL);
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
     * numberOfObjects is the property a list view asks for its row count, and it has to agree
     * with the collection it counts.
     *
     * It used to be computed once in the constructor, and buildSections constructs each section
     * with an empty collection before appending to it — so every grouped section reported zero
     * while ->objects filled up. The ungrouped path was unaffected, which is why the flat case
     * looked correct.
     */
    public function testGroupedSectionCountsItsObjects(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        foreach ($controller->sections as $section) {
            $this->assertSame($section->objects->count, $section->numberOfObjects, "section \"$section->name\" must count what it holds");
        }

        $office = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "office");
        $this->assertSame(2, $office->numberOfObjects);
    }

    /**
     * The count follows the collection after the fact too: a refresh appends to a section's
     * objects, and the row count has to move with them.
     */
    public function testSectionCountFollowsARefresh(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $recurring = new Expense($controller->managedObjectContext);
        $recurring->merchant = "Aardvark";
        $recurring->category = "office";
        $controller->managedObjectContext->processPendingChanges();

        $office = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "office");

        $this->assertSame(3, $office->numberOfObjects, "the count reflects the object the refresh added");
        $this->assertSame($office->objects->count, $office->numberOfObjects);
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
     * object() resolves a position to the object living there.
     */
    public function testObjectAtIndexPathReturnsTheObjectInThatSection(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        // "office" is the first section and holds Alpha then Beta.
        $this->assertSame("Alpha", $controller->object(new IndexPath([0, 0]))->merchant);
        $this->assertSame("Beta", $controller->object(new IndexPath([0, 1]))->merchant);
    }

    /**
     * indexPath() is the inverse, and it has to work for row 0.
     *
     * It used to test the row index for truthiness — `if ($row = $e->objects->indexOf($object))`
     * — and ArrayClass::indexOf returns 0 for a first element, so the first object of every
     * section was reported as not found. That is the row a list view scrolls to first.
     */
    public function testIndexPathFindsEveryRowIncludingTheFirst(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $first = $controller->object(new IndexPath([0, 0]));
        $second = $controller->object(new IndexPath([0, 1]));

        $firstPath = $controller->indexPath($first);
        $this->assertNotNull($firstPath, "row 0 is a valid position, not a miss");
        $this->assertSame(0, $firstPath->section);
        $this->assertSame(0, $firstPath->row);

        $secondPath = $controller->indexPath($second);
        $this->assertNotNull($secondPath);
        $this->assertSame(0, $secondPath->section);
        $this->assertSame(1, $secondPath->row);
    }

    /**
     * A position in a later section resolves to that section's number, not just to a row.
     */
    public function testIndexPathReportsTheSectionItFoundTheObjectIn(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        // "travel" is the third section; Mu is its first row.
        $travel = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "travel");
        $path = $controller->indexPath($travel->objects[0]);

        $this->assertNotNull($path);
        $this->assertSame(2, $path->section, "the object is in the third section");
        $this->assertSame(0, $path->row);
    }

    /**
     * An object the fetch never returned has no position.
     */
    public function testIndexPathOfAnUnfetchedObjectIsNull(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $stranger = new Expense($this->context());
        $stranger->merchant = "Omega";
        $stranger->category = "misc";

        $this->assertNull($controller->indexPath($stranger));
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
     * The regression this suite exists for: change tracking has to fire for a controller built
     * the way the project tells callers to build one.
     *
     * The filter compares `$object->entity->name` against the request's `entityName`, and
     * ManagedObject::fetchRequest() — the recommended factory — used to leave that null: every
     * comparison was "Expense" === null, the affected set came out empty, and the method returned
     * before refreshing anything. FetchRequest's `entity` setter now populates `entityName`
     * alongside it, so both halves of the identity are present however the request was built.
     */
    public function testInsertingAnObjectRefreshesTheResultsAndNotifiesTheDelegate(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $this->assertSame("Expense", $controller->fetchRequest->entityName, "setting the entity populates the name the filter compares");

        $delegate = new class implements FetchedResultsControllerDelegate {
            public bool $called = false;
            public int $insertions = 0;

            #[Override]
            public function controllerDidChangeContentWithSnapshot(FetchedResultsController $controller, mixed $snapshot): void
            {
            }

            #[Override]
            public function controllerDidChangeContentWithDifference(FetchedResultsController $controller, CollectionDifference $diff): void
            {
                $this->called = true;
                $this->insertions = $diff->insertions->count;
            }

            #[Override]
            public function controllerWillChangeContent(FetchedResultsController $controller): void
            {
            }

            #[Override]
            public function controllerDidChangeObject(FetchedResultsController $controller, mixed $object, ?IndexPath $indexPath, FetchedResultsChangeType $type, ?IndexPath $newIndexPath): void
            {
            }

            #[Override]
            public function controllerDidChangeSection(FetchedResultsController $controller, FetchedResultsSectionInfo $sectionInfo, int $sectionIndex, FetchedResultsChangeType $type): void
            {
            }

            #[Override]
            public function controllerDidChangeContent(FetchedResultsController $controller): void
            {
            }

            #[Override]
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

        $this->assertTrue($delegate->called, "the controller notifies its delegate on a context change");
        $this->assertSame($before + 1, $controller->fetchedObjects->count, "the inserted object joins the results");
        $this->assertSame(1, $delegate->insertions, "the difference reports the one insertion");
    }

    /**
     * A refresh re-groups, so an insertion has to land in the right section and in sort order —
     * not merely be appended to the flat result set.
     */
    public function testInsertedObjectIsGroupedIntoItsSection(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $expense = new Expense($controller->managedObjectContext);
        $expense->merchant = "Aardvark";
        $expense->category = "office";
        $controller->managedObjectContext->processPendingChanges();

        $office = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "office");

        $this->assertSame(["Aardvark", "Alpha", "Beta"], self::merchants($office), "the new object is sorted into its section");
    }

    /**
     * An object whose section value is new becomes a section of its own.
     */
    public function testInsertedObjectWithANewSectionValueAddsASection(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();

        $expense = new Expense($controller->managedObjectContext);
        $expense->merchant = "Omega";
        $expense->category = "postage";
        $controller->managedObjectContext->processPendingChanges();

        $this->assertSame(4, $controller->sections->count);
        $this->assertNotNull(
            $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "postage"),
            "the new category was re-grouped into its own section",
        );
    }

    /**
     * The refresh must only admit objects of the fetched entity.
     *
     * The entity filter used to sit on a variable that was read once, to decide whether to
     * refresh at all, and the refresh then appended the notification's whole inserted set. So a
     * batch holding one Expense and one Reminder passed the check on the Expense and admitted
     * both — and sorting the result by "merchant", which Reminder does not have, raised
     * UndefinedKeyException from inside the sort. The filter now runs where the objects are
     * added.
     */
    public function testAnUnrelatedEntityInTheSameBatchIsNotAdmitted(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $context = $controller->managedObjectContext;
        $expense = new Expense($context);
        $expense->merchant = "Omega";
        $expense->category = "office";
        $reminder = new Reminder($context);
        $reminder->note = "unrelated";
        $context->processPendingChanges();

        $this->assertSame($before + 1, $controller->fetchedObjects->count, "only the Expense joined the results");
        foreach ($controller->fetchedObjects as $object) {
            $this->assertSame("Expense", $object->entity->name, "no Reminder leaked into an Expense fetch");
        }
    }

    /**
     * A subentity does belong to a fetch on its parent, so it has to survive the same filter that
     * rejects an unrelated entity. This is what makes the check an isKindOf rather than a name
     * comparison, and it follows includesSubentities, which defaults to true.
     */
    public function testASubentityIsAdmittedIntoAParentEntityFetch(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $recurring = new RecurringExpense($controller->managedObjectContext);
        $recurring->merchant = "Aardvark";
        $recurring->category = "office";
        $recurring->period = "monthly";
        $controller->managedObjectContext->processPendingChanges();

        $this->assertSame($before + 1, $controller->fetchedObjects->count, "the subentity belongs to the parent's fetch");
        $office = $controller->sections->first(fn(FetchedResultsSectionInfo $section): bool => $section->name === "office");
        $this->assertSame(["Aardvark", "Alpha", "Beta"], self::merchants($office), "and is grouped and sorted with its section");
    }

    /**
     * With includesSubentities off, the fetch covers the parent entity alone.
     */
    public function testASubentityIsRejectedWhenSubentitiesAreExcluded(): void
    {
        $context = $this->context();
        $request = Expense::fetchRequest();
        $request->includesSubentities = false;
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("merchant", true)]);
        $controller = new FetchedResultsController($request, $context, "category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $recurring = new RecurringExpense($context);
        $recurring->merchant = "Aardvark";
        $recurring->category = "office";
        $recurring->period = "monthly";
        $context->processPendingChanges();

        $this->assertSame($before, $controller->fetchedObjects->count, "the subentity is excluded when the request excludes subentities");
    }

    /**
     * An update changes no membership, so the result count holds steady across a refresh.
     */
    public function testUpdatingAnObjectLeavesMembershipUnchanged(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $first = $controller->fetchedObjects[0];
        $first->merchant = $first->merchant . " (edited)";
        $controller->managedObjectContext->processPendingChanges();

        $this->assertSame($before, $controller->fetchedObjects->count, "an update adds and removes nothing");
    }

    /**
     * Without a delegate the controller still refreshes itself — the delegate is a notification
     * sink, not a precondition for tracking.
     */
    public function testRefreshHappensWithoutADelegate(): void
    {
        $controller = $this->controller("category");
        $controller->performFetch();
        $before = $controller->fetchedObjects->count;

        $expense = new Expense($controller->managedObjectContext);
        $expense->merchant = "Omega";
        $expense->category = "office";
        $controller->managedObjectContext->processPendingChanges();

        $this->assertSame($before + 1, $controller->fetchedObjects->count);
    }

    /**
     * The identity a fetch request carries has two halves, and the change filter can only read
     * one of them: `entity` resolves lazily through the context of the current operation queue,
     * so reading it outside one raises. Setting the entity therefore has to populate the name
     * too, which is what keeps the filter usable however the request was built — and what makes
     * `ManagedObject::fetchRequest()` a complete request rather than half of one.
     */
    public function testSettingTheEntityPopulatesTheEntityName(): void
    {
        $fromFactory = Expense::fetchRequest();
        $this->assertSame("Expense", $fromFactory->entityName, "the factory's request carries the name as well as the entity");

        $assignedByHand = new FetchRequest();
        $assignedByHand->entity = $fromFactory->entity;
        $this->assertSame("Expense", $assignedByHand->entityName, "assigning the entity directly does the same");

        $cleared = new FetchRequest();
        $cleared->entity = null;
        $this->assertNull($cleared->entityName, "clearing the entity clears the name with it");
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
