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
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\NotificationCenter;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\PersistentStoreCoordinatorStoresDidChange;
use const Sabatier\CoreData\PersistentStoreCoordinatorStoresWillChange;
use const Sabatier\CoreData\PersistentStoreCoordinatorWillRemoveStore;
use const Sabatier\CoreData\RemovedPersistentStoresKey;
use const Sabatier\CoreData\StoreTypeKey;
use const Sabatier\CoreData\StoreUUIDKey;

/**
 * @property string $body
 */
final class CoordinatorNote extends ManagedObject
{
}

final class CoordinatorTag extends ManagedObject
{
}

/**
 * Tests the store-management surface of PersistentStoreCoordinator.
 *
 * The coordinator is the hub every request passes through, and it appears in fifteen other
 * suites — but all of them call only addPersistentStoreWithType(). The remaining twenty-two
 * public methods had no coverage at all, including the three that destroy or replace data
 * (destroyPersistentStoreAtURL, replacePersistentStore, remove) and the one that reconstructs an
 * identity from an outside URI (managedObjectID), which is where a silent failure is most
 * costly: it returns null rather than raising, so a caller that does not check gets a missing
 * object instead of an error.
 *
 * Exercised against XMLObjectStore on a per-test temp file. That is a real store rather than a
 * double, so the store-lifecycle assertions (registration, lookup by URL, removal, on-disk
 * destruction) reflect what actually happens rather than what a mock was told to report.
 */
final class PersistentStoreCoordinatorTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;
    /** @var list<ManagedObjectContext> Every stack this test opened, released in tearDown. */
    private array $contexts = [];

    /**
     * A two-entity model. A fresh one per coordinator: entity descriptions freeze once bound.
     */
    private static function model(): ManagedObjectModel
    {
        $body = new AttributeDescription();
        $body->name = "body";
        $body->type = AttributeType::string;

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $note = new EntityDescription();
        $note->name = "CoordinatorNote";
        $note->managedObjectClassName = CoordinatorNote::class;
        $note->properties = new ArrayClass([$body]);

        $tag = new EntityDescription();
        $tag->name = "CoordinatorTag";
        $tag->managedObjectClassName = CoordinatorTag::class;
        $tag->properties = new ArrayClass([$label]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$note, $tag]);
        return $model;
    }

    private function coordinator(): PersistentStoreCoordinator
    {
        return new PersistentStoreCoordinator(self::model());
    }

    /**
     * A coordinator with one XML store added, plus a context bound to it so the stack is the
     * shape a caller actually builds.
     */
    private function stack(?URL $url = null): PersistentStoreCoordinator
    {
        $coordinator = $this->coordinator();
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $url ?? $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->contexts[] = $context;
        return $coordinator;
    }

    private function fileURL(string $path): URL
    {
        return new URL("file:///" . str_replace("\\", "/", $path));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-coordinator-" . uniqid("", true) . ".xml";
        $this->storeURL = $this->fileURL($this->storePath);
    }

    /**
     * Clearing the coordinator is what lets the stack be collected: assigning it registers the
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

    // --- Store registration and lookup ---

    public function testAddedStoreIsRegisteredWithTheCoordinator(): void
    {
        $coordinator = $this->stack();

        $this->assertSame(1, $coordinator->persistentStores->count, "the added store is the coordinator's only store");
    }

    /**
     * persistentStore() finds a store by the URL it was added with — the lookup a caller uses
     * to reach a store it did not keep a reference to.
     */
    public function testPersistentStoreFindsAStoreByItsURL(): void
    {
        $coordinator = $this->stack();

        $store = $coordinator->persistentStore($this->storeURL);

        $this->assertInstanceOf(PersistentStore::class, $store);
        $this->assertTrue($store->url->isEqual($this->storeURL), "the store found is the one at that URL");
    }

    /**
     * A URL no store was added with yields null rather than the first store or an exception.
     */
    public function testPersistentStoreReturnsNullForAnUnknownURL(): void
    {
        $coordinator = $this->stack();

        $this->assertNull($coordinator->persistentStore($this->fileURL(sys_get_temp_dir() . "/coredata-not-added.xml")));
    }

    public function testUrlReturnsTheStoreLocation(): void
    {
        $coordinator = $this->stack();
        $store = $coordinator->persistentStores[0];

        $this->assertTrue($coordinator->url($store)->isEqual($this->storeURL));
    }

    /**
     * persistentStoreForIdentifier() is the lookup managedObjectID() depends on, and the
     * identifier is what a ManagedObjectID URI carries as its host.
     */
    public function testPersistentStoreForIdentifierFindsTheStore(): void
    {
        $coordinator = $this->stack();
        $store = $coordinator->persistentStores[0];

        $this->assertSame($store, $coordinator->persistentStoreForIdentifier($store->identifier));
    }

    public function testPersistentStoreForIdentifierReturnsNullWhenUnknown(): void
    {
        $coordinator = $this->stack();

        $this->assertNull($coordinator->persistentStoreForIdentifier("no-such-store-identifier"));
    }

    /**
     * With a single store, every entity routes to it — the common case, and the fallback the
     * implementation ends on when no configuration claims the entity.
     */
    public function testPersistentStoreForObjectRoutesToTheOnlyStore(): void
    {
        $coordinator = $this->stack();
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->contexts[] = $context;

        $note = new CoordinatorNote($context);
        $note->body = "routed";

        $this->assertSame($coordinator->persistentStores[0], $coordinator->persistentStoreForObject($note));
    }

    // --- Identity from a URI ---

    /**
     * The round trip that matters for external references: a ManagedObjectID serialized to its
     * URI must come back as an equal ID. The URI is "x-coredata://{storeIdentifier}/{entity}/
     * {reference}", so this exercises the coordinator parsing all three parts back out.
     */
    public function testManagedObjectIDRoundTripsThroughItsURI(): void
    {
        $coordinator = $this->stack();
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->contexts[] = $context;

        $note = new CoordinatorNote($context);
        $note->body = "addressable";
        $context->save();

        $objectID = $note->objectID;
        $resolved = $coordinator->managedObjectID($objectID->uriRepresentation());

        $this->assertNotNull($resolved, "a URI produced by this coordinator must resolve back");
        $this->assertTrue($resolved->isEqual($objectID), "and resolve to an equal identity");
    }

    /**
     * An entity name absent from the model yields null. The method returns null rather than
     * raising, which is exactly why it needs a test: a caller that skips the null check gets a
     * missing object with no error to explain it.
     */
    public function testManagedObjectIDReturnsNullForAnUnknownEntity(): void
    {
        $coordinator = $this->stack();
        $store = $coordinator->persistentStores[0];

        $uri = new URL("x-coredata://" . $store->identifier . "/NoSuchEntity/1");

        $this->assertNull($coordinator->managedObjectID($uri));
    }

    /**
     * A URI naming a store this coordinator does not have also yields null, even when the
     * entity is one the model knows.
     */
    public function testManagedObjectIDReturnsNullForAnUnknownStore(): void
    {
        $coordinator = $this->stack();

        $uri = new URL("x-coredata://no-such-store-identifier/CoordinatorNote/1");

        $this->assertNull($coordinator->managedObjectID($uri));
    }

    // --- Metadata ---

    /**
     * A store's metadata carries the type and UUID the framework needs to recognise it later;
     * migration reads this to decide whether the cached model is compatible.
     */
    public function testStoreMetadataCarriesTypeAndUUID(): void
    {
        $coordinator = $this->stack();
        $store = $coordinator->persistentStores[0];

        $metadata = $coordinator->metadata($store);

        $this->assertSame(PersistentStoreType::xml->value, $metadata[StoreTypeKey], "the store type is recorded in the metadata");
        $this->assertNotNull($metadata[StoreUUIDKey], "and so is a UUID identifying the store");
    }

    /**
     * setMetadata() replaces the dictionary and announces the change, since a coordinator's
     * observers cache per-store information keyed by what the metadata says.
     */
    public function testSetMetadataStoresTheValueAndAnnouncesTheChange(): void
    {
        $coordinator = $this->stack();
        $store = $coordinator->persistentStores[0];

        $announced = false;
        $observer = NotificationCenter::default()->addObserverForName(
            PersistentStoreCoordinatorStoresDidChange,
            $coordinator,
            function () use (&$announced): void {
                $announced = true;
            },
        );

        $coordinator->setMetadata(new Dictionary(["CustomKey" => "custom value"]), $store);

        NotificationCenter::default()->removeObserver($observer);

        $this->assertSame("custom value", $coordinator->metadata($store)["CustomKey"], "the value set is what is read back");
        $this->assertTrue($announced, "changing a store's metadata notifies the coordinator's observers");
    }

    // --- Removal ---

    /**
     * remove() unregisters the store and announces it. The notifications are the contract other
     * parts of the stack rely on to drop what they cached for that store.
     */
    public function testRemoveUnregistersTheStoreAndAnnouncesIt(): void
    {
        $coordinator = $this->stack();
        $store = $coordinator->persistentStores[0];

        $names = [];
        $observers = [];
        foreach ([PersistentStoreCoordinatorStoresWillChange, PersistentStoreCoordinatorWillRemoveStore, PersistentStoreCoordinatorStoresDidChange] as $name) {
            $observers[] = NotificationCenter::default()->addObserverForName(
                $name,
                $coordinator,
                function () use (&$names, $name): void {
                    $names[] = $name;
                },
            );
        }

        $removed = $coordinator->remove($store);

        foreach ($observers as $observer) {
            NotificationCenter::default()->removeObserver($observer);
        }

        $this->assertTrue($removed);
        $this->assertSame(0, $coordinator->persistentStores->count, "the store is gone from the coordinator");
        $this->assertSame(
            [PersistentStoreCoordinatorStoresWillChange, PersistentStoreCoordinatorWillRemoveStore, PersistentStoreCoordinatorStoresDidChange],
            $names,
            "the will-change and will-remove notices precede the did-change one",
        );
    }

    /**
     * Removing a store leaves the file alone — removal detaches the store from the coordinator,
     * it does not destroy the data. That distinction is the whole difference between remove()
     * and destroyPersistentStoreAtURL().
     */
    public function testRemoveDoesNotDeleteTheStoreFile(): void
    {
        $coordinator = $this->stack();
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->contexts[] = $context;

        $note = new CoordinatorNote($context);
        $note->body = "survives removal";
        $context->save();
        $this->assertFileExists($this->storePath);

        $coordinator->remove($coordinator->persistentStores[0]);

        $this->assertFileExists($this->storePath, "removing a store from the coordinator must not delete its file");
    }

    /**
     * After removal, the store no longer answers a lookup by its URL.
     */
    public function testRemovedStoreIsNoLongerFoundByURL(): void
    {
        $coordinator = $this->stack();

        $coordinator->remove($coordinator->persistentStores[0]);

        $this->assertNull($coordinator->persistentStore($this->storeURL));
    }

    // --- Destruction ---

    /**
     * destroyPersistentStoreAtURL() is the destructive counterpart: it removes the store's
     * backing file. Pinned because nothing else exercises it, and its blast radius is the data.
     */
    public function testDestroyPersistentStoreRemovesTheBackingFile(): void
    {
        $coordinator = $this->stack();
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->contexts[] = $context;

        $note = new CoordinatorNote($context);
        $note->body = "doomed";
        $context->save();
        $this->assertFileExists($this->storePath);

        $coordinator->remove($coordinator->persistentStores[0]);
        $coordinator->destroyPersistentStoreAtURL($this->storeURL, PersistentStoreType::xml);

        $this->assertFileDoesNotExist($this->storePath, "destroying a store deletes its file");
    }

    /**
     * Destroying a store that was never created must not raise — the operation is the same
     * "make sure nothing is there" either way.
     */
    public function testDestroyPersistentStoreAtAnAbsentURLIsHarmless(): void
    {
        $coordinator = $this->coordinator();
        $absent = $this->fileURL(sys_get_temp_dir() . "/coredata-never-created-" . uniqid("", true) . ".xml");

        $coordinator->destroyPersistentStoreAtURL($absent, PersistentStoreType::xml);

        $this->assertFileDoesNotExist($absent->path);
    }
}
