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
use Sabatier\CoreData\ManagedObjectContextConcurrencyType;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentContainer;
use Sabatier\CoreData\PersistentStoreDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\SQLStoreType;
use const Sabatier\CoreData\XMLStoreType;

/**
 * @property string $label
 */
final class ContainerWidget extends ManagedObject
{
}

/**
 * Tests PersistentContainer — the entry point that assembles the whole stack, and the class an
 * application actually touches. It had no coverage of any kind.
 *
 * The container's job is wiring: it builds the model, the coordinator, the store descriptions and
 * the view context, and hands back something usable. Each of those is a decision a caller depends
 * on and cannot easily see, so what is pinned here is the shape of the stack it produces —
 * including the defaults, which are the part a caller inherits without asking.
 *
 * The default description targets a SQL store, so every test that actually opens a store swaps in
 * an XML description on a temp file. That keeps the suite runnable without a database while still
 * exercising the real loadPersistentStores path.
 */
final class PersistentContainerTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;
    /** @var list<ManagedObjectContext> Every context this test bound to a coordinator. */
    private array $contexts = [];

    private static function model(): ManagedObjectModel
    {
        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = "ContainerWidget";
        $entity->managedObjectClassName = ContainerWidget::class;
        $entity->properties = new ArrayClass([$label]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-container-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    /**
     * Clearing the coordinator is what lets a stack be collected: assigning it registers the
     * context as a notification observer, which otherwise keeps both alive for the process.
     */
    #[Override]
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

    private function container(): PersistentContainer
    {
        $container = new PersistentContainer("widgets", self::model());
        $this->contexts[] = $container->viewContext;
        return $container;
    }

    /**
     * Replaces the default SQL description with an XML one on this test's temp file, then loads.
     * Returns the errors the completion handler reported, so a caller can assert there were none.
     *
     * @return list<Error|null> one entry per store the container loaded
     */
    private function load(PersistentContainer $container): array
    {
        $description = new PersistentStoreDescription($this->storeURL);
        $description->type = XMLStoreType;
        $container->persistentStoreDescriptions = new ArrayClass([$description]);

        $errors = [];
        $container->loadPersistentStores(function (PersistentStoreDescription $description, ?Error $error) use (&$errors): void {
            $errors[] = $error;
        });
        return $errors;
    }

    // --- What the constructor builds ---

    public function testConstructorKeepsTheNameAndModel(): void
    {
        $model = self::model();
        $container = new PersistentContainer("widgets", $model);
        $this->contexts[] = $container->viewContext;

        $this->assertSame("widgets", $container->name);
        $this->assertSame($model, $container->managedObjectModel, "the model passed in is the one used, not one looked up by name");
    }

    /**
     * The coordinator is built over the container's model, and the view context is bound to that
     * coordinator — the wiring a caller would otherwise have to do by hand.
     */
    public function testTheStackIsWiredTogether(): void
    {
        $container = $this->container();

        $this->assertSame($container->managedObjectModel, $container->persistentStoreCoordinator->managedObjectModel);
        $this->assertSame($container->persistentStoreCoordinator, $container->viewContext->persistentStoreCoordinator);
    }

    /**
     * The default store description is SQL, named after the container. This is the decision a
     * caller inherits by saying nothing, and the reason a test that opens a store has to override
     * it: `sql://widgets` needs a database.
     */
    public function testTheDefaultDescriptionIsASQLStoreNamedAfterTheContainer(): void
    {
        $container = $this->container();

        $this->assertSame(1, $container->persistentStoreDescriptions->count);
        $description = $container->persistentStoreDescriptions[0];
        $this->assertSame(SQLStoreType, $description->type);
        $this->assertSame("sql://widgets", $description->url->absoluteString, "the container's name doubles as the store name");
    }

    /**
     * Automatic migration is on by default on every description the container creates, which is
     * why an application built this way migrates on launch without asking.
     */
    public function testTheDefaultDescriptionEnablesAutomaticMigration(): void
    {
        $container = $this->container();
        $description = $container->persistentStoreDescriptions[0];

        $this->assertTrue($description->shouldMigrateStoreAutomatically);
        $this->assertTrue($description->shouldInferMappingModelAutomatically);
    }

    public function testTheDefaultDescriptionCarriesTheContainerNameAsItsConfiguration(): void
    {
        $container = $this->container();

        $this->assertSame("widgets", $container->persistentStoreDescriptions[0]->configuration);
    }

    /**
     * No store is opened until loadPersistentStores is called: constructing the container is
     * cheap and cannot fail on a missing or unreachable store.
     */
    public function testNoStoreIsOpenedByTheConstructor(): void
    {
        $container = $this->container();

        $this->assertTrue($container->persistentStoreCoordinator->persistentStores->isEmpty);
    }

    // --- Loading ---

    /**
     * Loading adds the described store to the coordinator and reports no error.
     */
    public function testLoadPersistentStoresOpensTheDescribedStore(): void
    {
        $container = $this->container();

        $errors = $this->load($container);

        $this->assertSame([null], $errors, "the completion handler reports no error");
        $this->assertSame(1, $container->persistentStoreCoordinator->persistentStores->count);
        $this->assertSame(XMLStoreType, $container->persistentStoreCoordinator->persistentStores[0]->type);
    }

    /**
     * The completion handler runs once per store, which is what lets a caller with several stores
     * tell which one failed.
     */
    public function testTheCompletionHandlerRunsOncePerStore(): void
    {
        $container = $this->container();
        $secondPath = sys_get_temp_dir() . "/coredata-container-second-" . uniqid("", true) . ".xml";

        $first = new PersistentStoreDescription($this->storeURL);
        $first->type = XMLStoreType;
        $second = new PersistentStoreDescription(new URL("file:///" . str_replace("\\", "/", $secondPath)));
        $second->type = XMLStoreType;
        $container->persistentStoreDescriptions = new ArrayClass([$first, $second]);

        $seen = [];
        $container->loadPersistentStores(function (PersistentStoreDescription $description, ?Error $error) use (&$seen): void {
            $seen[] = $description->url->absoluteString;
        });

        if (file_exists($secondPath)) {
            unlink($secondPath);
        }

        $this->assertCount(2, $seen, "one call per description");
        $this->assertSame(2, $container->persistentStoreCoordinator->persistentStores->count);
    }

    /**
     * The stack is usable after loading: an object saved through the view context reaches the
     * store and comes back on a fresh fetch.
     */
    public function testTheViewContextCanSaveAndFetchAfterLoading(): void
    {
        $container = $this->container();
        $this->load($container);

        $widget = new ContainerWidget($container->viewContext);
        $widget->label = "saved through the container";
        $container->viewContext->save();

        $results = $container->viewContext->fetch(ContainerWidget::fetchRequest());

        $this->assertSame(1, $results->count);
        $this->assertSame("saved through the container", $results->first->label);
        $this->assertFileExists($this->storePath, "the save reached the store the container opened");
    }

    // --- Background contexts ---

    /**
     * A background context is a private-queue context parented to the view context, so its saves
     * land in the view context rather than going straight to the store.
     */
    public function testNewBackgroundContextIsAPrivateChildOfTheViewContext(): void
    {
        $container = $this->container();
        $this->load($container);

        $background = $container->newBackgroundContext();
        $this->contexts[] = $background;

        $this->assertSame(ManagedObjectContextConcurrencyType::privateQueueConcurrencyType, $background->concurrencyType);
        $this->assertSame($container->viewContext, $background->parent);
        $this->assertSame($container->persistentStoreCoordinator, $background->persistentStoreCoordinator);
    }

    /**
     * Each call hands back a distinct context — the point of the method is a fresh scratchpad, not
     * a shared one.
     */
    public function testEachBackgroundContextIsADistinctObject(): void
    {
        $container = $this->container();
        $this->load($container);

        $first = $container->newBackgroundContext();
        $second = $container->newBackgroundContext();
        $this->contexts[] = $first;
        $this->contexts[] = $second;

        $this->assertNotSame($first, $second);
    }

    /**
     * performBackgroundTask runs the block against a context it creates, on that context's own
     * queue.
     */
    public function testPerformBackgroundTaskRunsTheBlockWithItsOwnContext(): void
    {
        $container = $this->container();
        $this->load($container);

        $received = null;
        $container->performBackgroundTask(function (ManagedObjectContext $context) use (&$received): void {
            $received = $context;
        });

        $this->assertInstanceOf(ManagedObjectContext::class, $received, "the block is handed the context to work in");
        $this->assertNotSame($container->viewContext, $received, "and it is not the view context");
        $this->assertSame(ManagedObjectContextConcurrencyType::privateQueueConcurrencyType, $received->concurrencyType);
        $this->contexts[] = $received;
    }

    /**
     * Work done in a background task reaches the store, which is what makes the method useful for
     * a write that should not block the foreground.
     */
    public function testWorkDoneInABackgroundTaskIsPersisted(): void
    {
        $container = $this->container();
        $this->load($container);

        $container->performBackgroundTask(function (ManagedObjectContext $context): void {
            $widget = new ContainerWidget($context);
            $widget->label = "written in the background";
            $context->save();
        });

        // A child context saves into its parent, so the view context has to be saved as well for the change to reach the store.
        $container->viewContext->save();

        $results = $container->viewContext->fetch(ContainerWidget::fetchRequest());

        $this->assertSame(1, $results->count);
        $this->assertSame("written in the background", $results->first->label);
    }

    // --- Default directory ---

    /**
     * defaultDirectoryURL is where the container puts a file-backed store when the caller does not
     * say otherwise. It must be an existing directory, since the store is created inside it.
     */
    public function testDefaultDirectoryURLIsAnExistingDirectory(): void
    {
        $url = PersistentContainer::defaultDirectoryURL();

        $this->assertTrue($url->isFileURL, "a store directory is a file URL");
        $this->assertDirectoryExists($url->path);
    }
}
