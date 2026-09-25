<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\FetchedPropertyDescription;
use Sabatier\CoreData\IncrementalStore;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreAsynchronousResult;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * A store that implements nothing beyond the one abstract member PHP forces it to.
 *
 * This is the point of the class: PersistentStore is the protocol every backend implements, and
 * most of its methods exist only to be overridden. A subclass that skips one must fail loudly on
 * the call rather than returning something plausible, because a store that silently answered
 * "no values" to newValuesForObjectWithID would present every object as an empty fault.
 */
final class BareStore extends PersistentStore
{
    #[Override]
    public string $type {
        get => "bare";
    }
}

/** The same, one level down: IncrementalStore adds its own must-override method. */
final class BareIncrementalStore extends IncrementalStore
{
    #[Override]
    public string $type {
        get => "bare-incremental";
    }
}

/**
 * Covers the abstract store contract — the part of PersistentStore that has behaviour of its own
 * rather than being delegated to a backend.
 *
 * Three kinds of member live here and they are asserted differently. The must-override methods
 * are pinned by the failure they produce when a subclass forgets them. The template methods that
 * DO have a default (unload returning true, willRemove doing nothing, the cached model being
 * absent) are pinned by that default, because a subclass overriding one relies on what the base
 * promised. And objectID/referenceObject are real logic: the store owns an identity map, and
 * referenceObject is final precisely so no backend can reinterpret it.
 *
 * A bare subclass is used rather than a mock: the contract is about what an incomplete
 * implementation does, and a mock would implement everything.
 */
final class PersistentStoreContractTest extends TestCase
{
    private URL $storeURL;
    private PersistentStoreCoordinator $coordinator;

    private static function model(): ManagedObjectModel
    {
        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = "Thing";
        $entity->properties = new ArrayClass([$label]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private function entity(): EntityDescription
    {
        /** @var EntityDescription $entity */
        $entity = $this->coordinator->managedObjectModel->entitiesByName["Thing"];
        return $entity;
    }

    private function store(): BareStore
    {
        return new BareStore($this->coordinator, "default", $this->storeURL);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("bare");
        $this->coordinator = new PersistentStoreCoordinator(self::model());
    }

    // --- Must-override methods ---

    /**
     * Every method a backend is required to implement, named so a failure says which one.
     *
     * @return array<string, array{callable(BareStore, EntityDescription, ManagedObjectContext): mixed}>
     */
    public static function mustOverrideMethods(): array
    {
        return [
            "execute" => [static fn(BareStore $store, EntityDescription $entity, ManagedObjectContext $context): ArrayClass
                => $store->execute(new FetchRequest("Thing"), $context)],
            "newValuesForObjectWithID" => [static fn(BareStore $store, EntityDescription $entity, ManagedObjectContext $context): mixed
                => $store->newValuesForObjectWithID($store->objectID($entity, 1), $context)],
            "newValueForRelationship" => [static fn(BareStore $store, EntityDescription $entity, ManagedObjectContext $context): ArrayClass|ManagedObjectID|Nil
                => $store->newValueForRelationship(new RelationshipDescription(), $store->objectID($entity, 1), $context)],
            "newOrderedRelationshipInformationForRelationship" => [static fn(BareStore $store, EntityDescription $entity, ManagedObjectContext $context): ArrayClass|Nil
                => $store->newOrderedRelationshipInformationForRelationship(new RelationshipDescription(), $store->objectID($entity, 1), $context)],
            "newValueForFetchedProperty" => [static fn(BareStore $store, EntityDescription $entity, ManagedObjectContext $context): ArrayClass
                => $store->newValueForFetchedProperty(new FetchedPropertyDescription(), $store->objectID($entity, 1), $context)],
            "newReferenceObject" => [static fn(BareStore $store, EntityDescription $entity, ManagedObjectContext $context): int|string
                => $store->newReferenceObject(new ManagedObject($context))],
            "load" => [static fn(BareStore $store, EntityDescription $entity, ManagedObjectContext $context): bool
                => $store->load()],
        ];
    }

    /**
     * A subclass that skips a required method fails on the call. The alternative — a base class
     * returning a plausible empty value — would let an incomplete backend load a store and serve
     * blank objects, which is far harder to diagnose than an immediate failure.
     *
     * @param callable(BareStore, EntityDescription, ManagedObjectContext): mixed $call
     */
    #[DataProvider("mustOverrideMethods")]
    public function testAnUnimplementedMethodFailsRatherThanAnsweringPlausibly(callable $call): void
    {
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $this->coordinator;

        $this->expectException(InternalInconsistencyException::class);
        $call($this->store(), $this->entity(), $context);
    }

    /**
     * The two static metadata methods are required too, and they report the CALLED class rather
     * than the base — a diagnostic that names PersistentStore would send whoever hits it to the
     * wrong file.
     *
     * @throws Exception
     */
    public function testUnimplementedStaticMetadataNamesTheConcreteClass(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/BareStore/");
        BareStore::metadataForPersistentStore($this->storeURL);
    }

    /** @throws Exception */
    public function testUnimplementedSetMetadataAlsoFails(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        BareStore::setMetadata(new Dictionary(), $this->storeURL);
    }

    /**
     * IncrementalStore narrows the contract: it adds a typed newValuesForObjectWithID that a
     * chunk-loading backend must implement, and it is equally unforgiving when left out.
     *
     * @throws Exception
     */
    public function testIncrementalStoreAlsoDemandsItsOwnNewValues(): void
    {
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $this->coordinator;
        $store = new BareIncrementalStore($this->coordinator, "default", $this->storeURL);

        $this->expectException(InternalInconsistencyException::class);
        $store->newValuesForObjectWithID($store->objectID($this->entity(), 1), $context);
    }

    // --- Template methods that DO have a default ---

    /**
     * A store reports no cached model by default. The coordinator asks this before opening a
     * store to decide whether a migration is needed; answering "none" means "work it out from
     * the store itself", which is the only safe default for a backend that keeps no model copy.
     */
    public function testNoCachedModelByDefault(): void
    {
        $this->assertNull(BareStore::cachedModelForPersistentStoreWithURL($this->storeURL));
    }

    /**
     * unload() succeeds by default: a store with nothing to release is still correctly unloaded,
     * and a false here would make the coordinator treat a clean teardown as a failure.
     *
     * @throws Exception
     */
    public function testUnloadSucceedsByDefault(): void
    {
        $this->assertTrue($this->store()->unload());
    }

    /**
     * willRemove is a notification hook with no default behaviour — it exists for subclasses to
     * override. Calling it on a store that does not is a no-op rather than an error.
     */
    public function testWillRemoveIsANoOpByDefault(): void
    {
        $store = $this->store();

        $store->willRemove($this->coordinator);

        $this->assertSame("bare", $store->type, "the store is untouched by the notification");
    }

    /**
     * Registration notifications are no-ops too. A backend that caches per-context state
     * overrides them; one that does not must tolerate the call on every fetch.
     */
    public function testRegistrationNotificationsAreNoOpsByDefault(): void
    {
        $store = $this->store();
        $objectIDs = new ArrayClass([$store->objectID($this->entity(), 1)]);

        $store->managedObjectContextDidRegisterObjectsWithIDs($objectIDs, null);
        $store->managedObjectContextDidUnregisterObjectsWithIDs($objectIDs, null);

        $this->assertSame(1, $objectIDs->count, "the notifications neither consume nor alter what they are handed");
    }

    // --- Store-level file operations ---

    /**
     * Replacing a store moves the source file over the destination. The default is a plain move
     * because for a file-backed store that IS the replacement; a backend whose store is not one
     * file (the SQL one) overrides it.
     *
     * @throws Exception
     */
    public function testReplacingAStoreMovesTheSourceOverTheDestination(): void
    {
        $source = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("bare");
        FileManager::default()->createFile($source->path, "replacement");

        $this->assertTrue(BareStore::replacePersistentStoreAtURL($this->storeURL, null, $source, null));
        $this->assertTrue(FileManager::default()->fileExists($this->storeURL->path), "the destination now exists");
        $this->assertFalse(FileManager::default()->fileExists($source->path), "and the source was moved, not copied");

        FileManager::default()->removeItem($this->storeURL);
    }

    /**
     * Destroying a store that was never created succeeds: the caller asked for the store to be
     * gone, and it is. Reporting failure would make a coordinator treat a clean slate as an error.
     *
     * @throws Exception
     */
    public function testDestroyingAStoreThatDoesNotExistSucceeds(): void
    {
        $this->assertTrue(BareStore::destroyPersistentStoreAtURL($this->storeURL));
    }

    /**
     * And destroying one that does exist removes the file.
     *
     * @throws Exception
     */
    public function testDestroyingAStoreRemovesItsFile(): void
    {
        FileManager::default()->createFile($this->storeURL->path, "content");

        $this->assertTrue(BareStore::destroyPersistentStoreAtURL($this->storeURL));
        $this->assertFalse(FileManager::default()->fileExists($this->storeURL->path));
    }

    /**
     * An incremental store derives a new store's identifier from its URL, so the same location
     * always yields the same identifier — the identifier is what the row cache's generation
     * counter and the persistent history token are keyed by, and one that changed per process
     * would orphan both.
     */
    public function testIncrementalStoreIdentifierIsDerivedFromTheURL(): void
    {
        $other = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("bare");

        $this->assertSame(
            BareIncrementalStore::identifierForNewStore($this->storeURL),
            BareIncrementalStore::identifierForNewStore($this->storeURL),
            "the same URL always gives the same identifier",
        );
        $this->assertNotSame(
            BareIncrementalStore::identifierForNewStore($this->storeURL),
            BareIncrementalStore::identifierForNewStore($other),
            "and two stores never share one",
        );
    }

    // --- The identity map: objectID / referenceObject ---

    /**
     * objectID is an identity map, not a factory: asking twice for the same reference in the
     * same entity returns the very same object. Object IDs are compared across the graph, and a
     * store that minted a fresh one per call would make a registered object stop matching itself.
     */
    public function testObjectIDReturnsTheSameInstanceForTheSameReference(): void
    {
        $store = $this->store();

        $this->assertSame($store->objectID($this->entity(), 42), $store->objectID($this->entity(), 42));
    }

    /**
     * The map is keyed by the reference as a string, so the int 42 and the string "42" name one
     * row — which is what lets a backend that reads keys back from the database as strings match
     * the IDs it minted from integers.
     */
    public function testObjectIDTreatsANumericReferenceAndItsStringFormAsOne(): void
    {
        $store = $this->store();

        $this->assertSame($store->objectID($this->entity(), 42), $store->objectID($this->entity(), "42"));
    }

    /**
     * Distinct references are distinct identities.
     */
    public function testDifferentReferencesGiveDifferentObjectIDs(): void
    {
        $store = $this->store();

        $this->assertNotSame($store->objectID($this->entity(), 1), $store->objectID($this->entity(), 2));
    }

    /**
     * An object ID knows which store minted it — that back-reference is how the coordinator
     * routes a fault to the right store when several are attached.
     */
    public function testObjectIDPointsBackAtTheStoreThatMintedIt(): void
    {
        $store = $this->store();

        $this->assertSame($store, $store->objectID($this->entity(), 1)->persistentStore);
    }

    /**
     * referenceObject() round-trips an ID the store minted: objectID() indexes the identity map
     * under the reference as a string, and referenceObject() reads it back with the same key.
     * The two once disagreed — the read used `(string)$objectID`, the ID's full URI description
     * — so every lookup fell through to the fatal_error, including this one.
     *
     * The docblock tells subclasses to invoke this method to extract the reference data for each
     * cache node, and the method is final, so this round trip is the whole of what an out-of-tree
     * backend is promised.
     */
    public function testReferenceObjectRoundTripsAnIDTheStoreMinted(): void
    {
        $store = $this->store();

        $this->assertSame(7, $store->referenceObject($store->objectID($this->entity(), 7)));
    }

    /**
     * A string reference round-trips as the string it was minted from. The map keys the int 7 and
     * the string "7" to one row, but what comes back is the reference the ID actually carries, so
     * a backend whose keys are UUIDs or slugs gets its own value rather than a coerced one.
     */
    public function testReferenceObjectReturnsTheReferenceInItsOriginalType(): void
    {
        $store = $this->store();

        $this->assertSame("row-7", $store->referenceObject($store->objectID($this->entity(), "row-7")));
    }

    /**
     * It refuses an ID from another store — the identity map is the store's own, and a backend
     * that accepted a foreign ID would read a row number belonging to a different database.
     */
    public function testReferenceObjectRefusesAnIDFromAnotherStore(): void
    {
        $foreign = $this->store()->objectID($this->entity(), 7);

        $this->expectException(InternalInconsistencyException::class);
        $this->store()->referenceObject($foreign);
    }

    // --- PersistentStoreAsynchronousResult ---

    /**
     * The async result keeps the context its request was issued against, so a completion handler
     * can reach the scratchpad the results belong to.
     */
    public function testAsynchronousResultKeepsItsContext(): void
    {
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $this->coordinator;

        $this->assertSame($context, new PersistentStoreAsynchronousResult($context)->managedObjectContext);
    }

    /**
     * cancel() is a no-op on the base result: cancellation is meaningful only for a subclass that
     * has work in flight, and the base must tolerate the call so a caller can cancel uniformly.
     */
    public function testCancellingTheBaseResultIsANoOp(): void
    {
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $this->coordinator;
        $result = new PersistentStoreAsynchronousResult($context);

        $result->cancel();

        $this->assertSame($context, $result->managedObjectContext, "cancelling does not release the context");
    }
}
