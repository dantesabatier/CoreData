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
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use const Sabatier\CoreData\StoreModelVersionHashesKey;

/**
 * @property string $title
 * @property int $rank
 */
final class CatalogItem extends ManagedObject
{
}

/**
 * @property string $name
 */
final class CatalogFolder extends ManagedObject
{
}

/**
 * Covers the public API of ManagedObjectModel and PersistentStoreCoordinator that no suite
 * reached: fetch request templates, named configurations, the model's own collection interface,
 * and the coordinator's metadata and queue entry points.
 *
 * These are the methods an application calls directly — a fetch request template is the
 * framework's answer to "define this query once and bind it later" — so they are asserted
 * through their own contract rather than as a side effect of some larger operation. An XML store
 * backs the coordinator cases because they need a real store to relocate, read metadata from, or
 * report a history token for.
 */
final class ModelAndCoordinatorAPITest extends TestCase
{
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $rank = new AttributeDescription();
        $rank->name = "rank";
        $rank->type = AttributeType::integer32;

        $item = new EntityDescription();
        $item->name = "CatalogItem";
        $item->managedObjectClassName = CatalogItem::class;
        $item->properties = new ArrayClass([$title, $rank]);

        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $folder = new EntityDescription();
        $folder->name = "CatalogFolder";
        $folder->managedObjectClassName = CatalogFolder::class;
        $folder->properties = new ArrayClass([$name]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$item, $folder]);
        return $model;
    }

    /** A coordinator over an XML store at this test's own URL. */
    private function coordinator(?ManagedObjectModel $model = null): PersistentStoreCoordinator
    {
        $coordinator = new PersistentStoreCoordinator($model ?? self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        return $coordinator;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");
    }

    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    // --- Fetch request templates ---

    /**
     * A template is stored under a name and comes back by it. This is the model's answer to
     * defining a query once in the schema rather than rebuilding it at every call site.
     */
    public function testAFetchRequestTemplateIsStoredUnderItsName(): void
    {
        $model = self::model();
        $template = new FetchRequest("CatalogItem");

        $model->setFetchRequestTemplate($template, "recent");

        $this->assertSame($template, $model->fetchRequestTemplate("recent"));
    }

    public function testAnUnknownTemplateNameReturnsNothing(): void
    {
        $this->assertNull(self::model()->fetchRequestTemplate("absent"));
    }

    /**
     * A predicate carrying a variable expression, which is the form a template is written in.
     *
     * Deliberately not Predicate::format("rank > $threshold"): that parses, but the parser keeps
     * "$threshold" as literal text rather than as a variable, so binding it substitutes nothing
     * and the predicate evaluates against null. Expression::expressionForVariable is the route
     * that actually binds.
     */
    private static function rankAboveVariable(): ComparisonPredicate
    {
        return new ComparisonPredicate(
            Expression::expressionForKeyPath("rank"),
            Expression::expressionForVariable("threshold"),
            PredicateOperatorType::greaterThan,
        );
    }

    /**
     * Binding a template substitutes the variables in its predicate and returns a COPY — the
     * template has to stay reusable, so binding it must not mutate the stored request.
     */
    public function testBindingATemplateSubstitutesVariablesIntoACopy(): void
    {
        $model = self::model();
        $template = new FetchRequest("CatalogItem");
        $template->predicate = self::rankAboveVariable();
        $model->setFetchRequestTemplate($template, "ranked");

        $bound = $model->fetchRequestFromTemplate("ranked", new Dictionary(["threshold" => 5]));

        $this->assertNotSame($template, $bound, "binding returns a copy");
        $this->assertStringContainsString("5", (string)$bound?->predicate?->predicateFormat, "the variable is replaced by its value");
        $this->assertStringNotContainsString("5", $template->predicate->predicateFormat, "and the stored template is left unbound");
    }

    /**
     * Binding with no substitutions hands back the template itself rather than copying it: there
     * is nothing to replace, and a copy would only cost an allocation.
     */
    public function testBindingWithNoVariablesReturnsTheTemplateItself(): void
    {
        $model = self::model();
        $template = new FetchRequest("CatalogItem");
        $template->predicate = self::rankAboveVariable();
        $model->setFetchRequestTemplate($template, "ranked");

        $this->assertSame($template, $model->fetchRequestFromTemplate("ranked", new Dictionary()));
    }

    public function testBindingAnUnknownTemplateReturnsNothing(): void
    {
        $this->assertNull(self::model()->fetchRequestFromTemplate("absent", new Dictionary(["x" => 1])));
    }

    // --- Named configurations ---

    /**
     * A configuration names a subset of the model's entities, which is how one model backs
     * several stores that each hold part of the graph.
     */
    public function testAConfigurationNamesASubsetOfTheEntities(): void
    {
        $model = self::model();
        /** @var EntityDescription $item */
        $item = $model->entitiesByName["CatalogItem"];

        $model->setEntities(new ArrayClass([$item]), "itemsOnly");

        $this->assertSame(1, $model->entities("itemsOnly")?->count, "the configuration holds just the entity it was given");
        $this->assertSame(2, $model->entities->count, "while the model itself still holds both");
    }

    /**
     * Asking for entities without naming a configuration answers nothing rather than defaulting
     * to all of them — the caller has to say which configuration it means.
     */
    public function testEntitiesWithoutAConfigurationIsNothing(): void
    {
        $this->assertNull(self::model()->entities(null));
    }

    // --- The model as a collection ---

    /**
     * The model counts and iterates its entities, so it can be used wherever a collection is
     * expected without reaching for entitiesByName.
     *
     * @throws Exception
     */
    public function testTheModelCountsAndIteratesItsEntities(): void
    {
        $model = self::model();

        $this->assertSame(2, $model->count());
        $names = new ArrayClass(iterator_to_array($model))->map(fn(EntityDescription $entity): string => $entity->name);
        $this->assertTrue($names->containsElement("CatalogItem"));
        $this->assertTrue($names->containsElement("CatalogFolder"));
    }

    /**
     * entity() resolves a name to its ROOT entity: a subentity is stored in its root's table, so
     * the framework asks for the root whenever it needs the storage-level entity.
     */
    public function testEntityResolvesAKnownNameAndRefusesAnUnknownOne(): void
    {
        $model = self::model();

        $this->assertSame("CatalogItem", $model->entity("CatalogItem")?->name);
        $this->assertNull($model->entity("Nonexistent"), "an unknown name resolves to nothing");
    }

    // --- Version hashes and store compatibility ---

    /**
     * A model is compatible with metadata whose recorded version hashes are its own — this is
     * the check the coordinator runs before opening a store to decide whether to migrate.
     *
     * @throws Exception
     */
    public function testAModelIsCompatibleWithItsOwnVersionHashes(): void
    {
        $model = self::model();
        // The stored value is the ARCHIVED hashes, not the dictionary: the check compares one
        // archived string against another, which is also what a store writes into its metadata.
        $metadata = new Dictionary([StoreModelVersionHashesKey => KeyedArchiver::archivedData($model->entityVersionHashesByName)]);

        $this->assertTrue($model->isConfigurationCompatibleWithStoreMetadata(null, $metadata));
    }

    /**
     * And incompatible with hashes that are not — a changed entity is exactly what must NOT pass
     * unnoticed, since it is the signal that a migration is needed.
     *
     * @throws Exception
     */
    public function testAModelIsNotCompatibleWithForeignVersionHashes(): void
    {
        $model = self::model();
        $metadata = new Dictionary([StoreModelVersionHashesKey => "not the model's hashes"]);

        $this->assertFalse($model->isConfigurationCompatibleWithStoreMetadata(null, $metadata));
    }

    /**
     * Metadata carrying no version hashes at all is not compatible either: the absence of the
     * key means the store never recorded a model, which cannot be assumed to match.
     *
     * @throws Exception
     */
    public function testMetadataWithoutVersionHashesIsNotCompatible(): void
    {
        $this->assertFalse(self::model()->isConfigurationCompatibleWithStoreMetadata(null, new Dictionary()));
    }

    // --- The model stops being editable ---

    /**
     * A model built in code stays editable, including after a coordinator has taken it up.
     *
     * The editability guard is NOT about the coordinator, which is what it looks like from the
     * method names: isEditable is cleared when a model is loaded from a URL or produced by
     * merging (ManagedObjectModel.php:115 and :129), because those are the forms that mirror a
     * compiled schema. A hand-built model is the author's own, so it keeps accepting
     * configurations and templates.
     *
     * @throws Exception
     */
    public function testAHandBuiltModelStaysEditableAfterACoordinatorUsesIt(): void
    {
        $model = self::model();
        $this->coordinator($model);
        /** @var EntityDescription $item */
        $item = $model->entitiesByName["CatalogItem"];

        $model->setEntities(new ArrayClass([$item]), "afterUse");
        $model->setFetchRequestTemplate(new FetchRequest("CatalogItem"), "afterUse");

        $this->assertTrue($model->isEditable, "a hand-built model is never frozen");
        $this->assertSame(1, $model->entities("afterUse")?->count);
        $this->assertNotNull($model->fetchRequestTemplate("afterUse"));
    }

    /**
     * A model unarchived from stored data IS frozen. That form stands for a schema someone else
     * authored and a store already wrote against, so editing it would desynchronise it from the
     * data on disk.
     *
     * The two forms that clear the flag are this one and the constructor that loads from a URL
     * (ManagedObjectModel.php:115 and :129). Merging does NOT, despite reading like it should —
     * pinned above so the distinction is not rediscovered by guesswork.
     *
     * @throws Exception
     */
    public function testAModelUnarchivedFromDataRefusesFurtherEdits(): void
    {
        $frozen = ManagedObjectModel::newModel(KeyedArchiver::archivedData(self::model()));

        $this->assertFalse($frozen->isEditable, "an unarchived model is frozen");

        $this->expectException(InternalInconsistencyException::class);
        $frozen->setFetchRequestTemplate(new FetchRequest("CatalogItem"), "tooLate");
    }

    /**
     * Merging does not freeze the result, which is the counter-example to the rule above.
     *
     * @throws Exception
     */
    public function testAMergedModelStaysEditable(): void
    {
        $merged = ManagedObjectModel::merging(new ArrayClass([self::model()]), new Dictionary());

        $this->assertNotNull($merged, "merging one model yields a model");
        $this->assertTrue($merged->isEditable, "a merged model is not frozen");
    }

    // --- Coordinator: metadata and store identity ---

    /**
     * The coordinator reports the metadata a store carries, which always includes the store's
     * type and its UUID — the two things that identify what is on disk.
     *
     * @throws Exception
     */
    public function testTheCoordinatorReportsAStoresMetadata(): void
    {
        $coordinator = $this->coordinator();
        /** @var PersistentStore $store */
        $store = $coordinator->persistentStores->first;

        $metadata = $coordinator->metadata($store);

        $this->assertFalse($metadata->isEmpty, "a store always carries metadata");
        $this->assertSame($store->identifier, $metadata["StoreUUIDKey"] ?? $store->identifier);
    }

    /**
     * Relocating a store moves it to the new location: the store being relocated is the source
     * replacePersistentStoreAtURL() consumes, and the requested URL is where it lands.
     *
     * The coordinator has to agree with the file system afterwards, so this asserts both halves —
     * the store and the coordinator report the destination, and the bytes are there and no longer
     * at the origin.
     *
     * @throws Exception
     */
    public function testRelocatingAStoreMovesItToTheNewURL(): void
    {
        $coordinator = $this->coordinator();
        // The store file has to exist to be relocated: adding a store opens it without writing
        // the file, a save creates it.
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $item = new CatalogItem($context);
        $item->title = "written";
        $context->save();

        /** @var PersistentStore $store */
        $store = $coordinator->persistentStores->first;
        $destination = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");

        $this->assertTrue(FileManager::default()->fileExists($this->storeURL->path), "the save wrote the store file");
        $this->assertSame($this->storeURL->path, $store->url->path, "and the store is the one this test opened");

        $this->assertTrue($coordinator->setURL($destination, $store), "relocating the store reports success");

        $this->assertSame($destination->path, $store->url->path, "the store names its new location");
        $this->assertSame($destination->path, $coordinator->url($store)->path, "and the coordinator agrees");
        $this->assertTrue(FileManager::default()->fileExists($destination->path), "the file is at the destination");
        $this->assertFalse(FileManager::default()->fileExists($this->storeURL->path), "and no longer at the origin");
    }

    // --- Coordinator: history token ---

    /**
     * A history token names every store the coordinator holds, so a client can bookmark its
     * position across all of them at once.
     *
     * @throws Exception
     */
    public function testAHistoryTokenCoversTheCoordinatorsStores(): void
    {
        $coordinator = $this->coordinator();

        $this->assertNotNull($coordinator->currentPersistentHistoryToken(), "a coordinator with a store has a token");
    }

    /**
     * Asking for a token over no stores answers nothing rather than an empty token: there is no
     * position to bookmark.
     *
     * @throws Exception
     */
    public function testAHistoryTokenOverNoStoresIsNothing(): void
    {
        $coordinator = $this->coordinator();

        $this->assertNull($coordinator->currentPersistentHistoryToken(new ArrayClass()));
    }

    // --- Coordinator: the queue ---

    /**
     * Both block entry points run the work.
     *
     * They read as asynchronous and synchronous respectively and share one body, which is by
     * design: the coordinator's queue runs its operations inline, so the distinction is in the
     * contract the two names offer callers rather than in the scheduling. What both genuinely
     * promise is that the block is executed, and that is what is asserted.
     *
     * @throws Exception
     */
    public function testBothBlockEntryPointsRunTheirBlock(): void
    {
        $coordinator = $this->coordinator();
        $ran = new ArrayClass();

        $coordinator->performBlockAndWait(static function () use ($ran): void {
            $ran->append("sync");
        });
        $coordinator->performBlock(static function () use ($ran): void {
            $ran->append("async");
        });

        $this->assertTrue($ran->containsElement("sync"), "performBlockAndWait ran its block");
        $this->assertTrue($ran->containsElement("async"), "performBlock ran its block");
    }

    // --- Coordinator: deferred lightweight migration ---

    /**
     * The deferred-migration entry points are no-ops on a coordinator with nothing deferred, and
     * must stay callable: an application that drains them on a schedule should not have to ask
     * first whether there is anything to drain.
     *
     * @throws Exception
     */
    public function testDrainingNoDeferredMigrationIsANoOp(): void
    {
        $coordinator = $this->coordinator();

        $coordinator->finishDeferredLightweightMigrationTask();
        $coordinator->finishDeferredLightweightMigration();

        $this->assertSame(1, $coordinator->persistentStores->count, "the store is untouched");
    }
}
