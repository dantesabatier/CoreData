<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AtomicStore;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property string $title
 * @property Set<AtomicChapter> $chapters
 * @method void addChaptersObject(AtomicChapter $object)
 * @method void removeChaptersObject(AtomicChapter $object)
 * @method void addChapters(Set<AtomicChapter> $objects)
 * @method void removeChapters(Set<AtomicChapter> $objects)
 * @method Set<AtomicChapter> intersectChapters(Set<AtomicChapter> $objects)
 * @method void setChapters(Set<AtomicChapter> $objects)
 */
final class AtomicBook extends ManagedObject
{
}

/**
 * @property string $heading
 * @property AtomicBook|null $book
 */
final class AtomicChapter extends ManagedObject
{
}

/**
 * Covers how an atomic store resolves relationships and refreshes objects — the work SQL
 * delegates to the database and this store family has to do in PHP.
 *
 * AtomicStore keeps every object as a cache node in memory and answers a relationship by
 * scanning those nodes, so each cardinality is a different branch: a to-many is found by asking
 * every candidate node whether its inverse names this object, while a to-one whose inverse is
 * to-many can only be read from the owning node itself. That last one is the case a fault
 * re-fires through, and getting it wrong leaves a relationship that can never be resolved again.
 *
 * Exercised through XMLObjectStore, since AtomicStore is abstract and MemoryObjectStore has no
 * load(). Each test gets its own temporary file.
 */
final class AtomicStoreRelationshipTest extends TestCase
{
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $chapters = new RelationshipDescription();
        $chapters->name = "chapters";
        $chapters->lazyDestinationEntityName = "AtomicChapter";
        $chapters->lazyInverseRelationshipName = "book";
        $chapters->isToMany = true;

        $book = new EntityDescription();
        $book->name = "AtomicBook";
        $book->managedObjectClassName = AtomicBook::class;
        $book->properties = new ArrayClass([$title, $chapters]);

        $heading = new AttributeDescription();
        $heading->name = "heading";
        $heading->type = AttributeType::string;

        $bookRelationship = new RelationshipDescription();
        $bookRelationship->name = "book";
        $bookRelationship->lazyDestinationEntityName = "AtomicBook";
        $bookRelationship->lazyInverseRelationshipName = "chapters";

        $chapter = new EntityDescription();
        $chapter->name = "AtomicChapter";
        $chapter->managedObjectClassName = AtomicChapter::class;
        $chapter->properties = new ArrayClass([$heading, $bookRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$book, $chapter]);
        return $model;
    }

    /** A fresh stack over this test's own store file. @throws Exception */
    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /** The store behind a context. @throws Exception */
    private function store(ManagedObjectContext $context): PersistentStore
    {
        $store = $context->persistentStoreCoordinator?->persistentStores->first;
        $this->assertInstanceOf(PersistentStore::class, $store, "the stack must have a store");
        return $store;
    }

    /** @throws Exception */
    private function relationship(ManagedObjectContext $context, string $entityName, string $name): RelationshipDescription
    {
        /** @var RelationshipDescription $relationship */
        $relationship = EntityDescription::entity($entityName, $context)->relationshipsByName[$name];
        return $relationship;
    }

    /**
     * A book with the given chapter headings, saved.
     *
     * @param list<string> $headings
     * @throws Exception
     */
    private function seed(ManagedObjectContext $context, string $title, array $headings): AtomicBook
    {
        $book = new AtomicBook($context);
        $book->title = $title;
        foreach ($headings as $heading) {
            $chapter = new AtomicChapter($context);
            $chapter->heading = $heading;
            $chapter->book = $book;
        }
        $context->save();
        return $book;
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

    // --- Resolving relationships from the node cache ---

    /**
     * A to-many is resolved by scanning the cache for nodes whose inverse names this object.
     *
     * @throws Exception
     */
    public function testAToManyResolvesEveryMemberFromTheNodeCache(): void
    {
        $this->seed($this->context(), "Book", ["One", "Two", "Three"]);

        $fresh = $this->context();
        /** @var AtomicBook $book */
        $book = $fresh->fetch(AtomicBook::fetchRequest())->first;
        $value = $this->store($fresh)->newValueForRelationship(
            $this->relationship($fresh, "AtomicBook", "chapters"),
            $book->objectID,
            $fresh,
        );

        $this->assertInstanceOf(ArrayClass::class, $value, "a to-many resolves to a list of IDs");
        $this->assertSame(3, $value->count, "one entry per member");
    }

    /**
     * A to-one whose inverse is to-many is read from the OWNING node rather than by scanning
     * the destination: only this side records the single ID. This is the branch a re-fired
     * fault goes through, so a relationship that resolved once has to resolve again.
     *
     * @throws Exception
     */
    public function testAToOneWithAToManyInverseResolvesFromItsOwnNode(): void
    {
        $this->seed($this->context(), "Book", ["Only"]);

        $fresh = $this->context();
        /** @var AtomicChapter $chapter */
        $chapter = $fresh->fetch(AtomicChapter::fetchRequest())->first;
        $value = $this->store($fresh)->newValueForRelationship(
            $this->relationship($fresh, "AtomicChapter", "book"),
            $chapter->objectID,
            $fresh,
        );

        $this->assertInstanceOf(ManagedObjectID::class, $value, "the to-one resolves to the owner's ID");
    }

    /**
     * An empty to-many that is required resolves to an empty list rather than to nothing: the
     * collection exists, it simply has no members, and a Nil would read as "not loaded".
     *
     * @throws Exception
     */
    public function testAnEmptyToManyResolvesToAnEmptyList(): void
    {
        $context = $this->context();
        $book = new AtomicBook($context);
        $book->title = "Empty";
        $context->save();

        $fresh = $this->context();
        /** @var AtomicBook $stored */
        $stored = $fresh->fetch(AtomicBook::fetchRequest())->first;
        $value = $this->store($fresh)->newValueForRelationship(
            $this->relationship($fresh, "AtomicBook", "chapters"),
            $stored->objectID,
            $fresh,
        );

        $this->assertInstanceOf(ArrayClass::class, $value);
        $this->assertTrue($value->isEmpty, "a book with no chapters resolves to an empty list");
    }

    /**
     * A to-one that was never set resolves to Nil — absent, as opposed to an empty collection.
     *
     * @throws Exception
     */
    public function testAnUnsetToOneResolvesToNil(): void
    {
        $context = $this->context();
        $chapter = new AtomicChapter($context);
        $chapter->heading = "Orphan";
        $context->save();

        $fresh = $this->context();
        /** @var AtomicChapter $stored */
        $stored = $fresh->fetch(AtomicChapter::fetchRequest())->first;
        $value = $this->store($fresh)->newValueForRelationship(
            $this->relationship($fresh, "AtomicChapter", "book"),
            $stored->objectID,
            $fresh,
        );

        $this->assertInstanceOf(Nil::class, $value, "an unset to-one is absent, not empty");
    }

    /**
     * Relationships are scoped to their owner: two books do not see each other's chapters.
     *
     * @throws Exception
     */
    public function testRelationshipsAreScopedToTheirOwner(): void
    {
        $context = $this->context();
        $this->seed($context, "First", ["A", "B"]);
        $this->seed($context, "Second", ["C"]);

        $fresh = $this->context();
        $counts = $fresh->fetch(AtomicBook::fetchRequest())->reduce([],
            /**
             * @param array<string, int> $carry
             * @param AtomicBook $book
             * @return array<string, int>
             */
            static function (array &$carry, AtomicBook $book): array {
                $carry[$book->title] = $book->chapters->count;
                return $carry;
            });

        $this->assertSame(["First" => 2, "Second" => 1], $counts);
    }

    // --- The store's own values for an object ---

    /**
     * The store answers an object's attributes from its cache node, which is what a fault reads
     * to materialize itself.
     *
     * @throws Exception
     */
    public function testTheStoreAnswersAnObjectsStoredValues(): void
    {
        $this->seed($this->context(), "Stored", ["One"]);

        $fresh = $this->context();
        /** @var AtomicBook $book */
        $book = $fresh->fetch(AtomicBook::fetchRequest())->first;
        $values = $this->store($fresh)->newValuesForObjectWithID($book->objectID, $fresh);

        $this->assertNotNull($values, "an object the store holds has values");
    }

    // --- Refreshing ---

    /**
     * Refreshing an object of an atomic store does NOT discard a pending edit, whichever way
     * mergeChanges is set, and this pins that as it stands.
     *
     * refresh() refaults the object so the next read comes from the store. For a SQL store that
     * is enough, because the row is the authority. An atomic store holds its objects as cache
     * nodes it marks as inserted — verified: isInserted is true even for an object a fresh
     * context just fetched — so refaulting finds the in-memory value as the stored one and the
     * edit survives. Both flags are asserted so the symmetry is on the record rather than
     * discovered by whoever first relies on refresh to drop a change here.
     *
     * @throws Exception
     */
    public function testRefreshingDoesNotDiscardAPendingEditInAnAtomicStore(): void
    {
        $context = $this->context();
        $book = $this->seed($context, "Original", ["One"]);

        $book->title = "Edited without merging";
        $context->refresh($book, false);
        $this->assertNotSame("Original", $book->title, "mergeChanges false does not restore the stored value");

        $book->title = "Edited with merging";
        $context->refresh($book, true);
        $this->assertNotSame("Original", $book->title, "and mergeChanges true keeps the edit too, by reapplying it");
    }

    /**
     * A refresh leaves the object's relationships intact: they are re-resolved from the store
     * rather than blanked, so an object refreshed mid-graph does not lose its links.
     *
     * @throws Exception
     */
    public function testARefreshedObjectStillResolvesItsRelationship(): void
    {
        $context = $this->context();
        $book = $this->seed($context, "Original", ["One", "Two"]);

        $context->refresh($book, false);

        $this->assertSame(2, $book->chapters->count, "the relationship re-resolves after the refault");
    }

    // --- The abstract store's own contract ---

    /**
     * An atomic backend that implements nothing beyond the members PHP forces on it.
     *
     * AtomicStore leaves the reading and writing of the file to its subclass and keeps the graph
     * work for itself, so the methods a subclass must supply have to fail on the call rather
     * than return something plausible — a store that silently answered "no node" would present
     * every object as empty.
     *
     * @throws Exception
     */
    private function bareAtomicStore(): AtomicStore
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        return new class($coordinator, "default", $this->storeURL) extends AtomicStore {
            #[Override]
            public string $type {
                get => "bare-atomic";
            }
        };
    }

    /** @throws Exception */
    public function testABareAtomicStoreDemandsItsCacheNodeFactory(): void
    {
        $context = $this->context();
        $book = new AtomicBook($context);
        $book->title = "unsaved";

        $this->expectException(InternalInconsistencyException::class);
        $this->bareAtomicStore()->newCacheNode($book);
    }

    /** @throws Exception */
    public function testABareAtomicStoreDemandsItsSave(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        $this->bareAtomicStore()->save();
    }

    /** @throws Exception */
    public function testABareAtomicStoreDemandsItsLoad(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        $this->bareAtomicStore()->load();
    }

    /**
     * The two hooks around the node cache do nothing by default: they exist for a subclass that
     * tracks what is about to be written, and a backend that does not must tolerate the call.
     *
     * @throws Exception
     */
    public function testTheCacheNodeHooksAreNoOpsByDefault(): void
    {
        $store = $this->bareAtomicStore();

        $store->willRemoveCacheNodes(new Set());

        $this->assertTrue($store->cacheNodes()->isEmpty, "a store that loaded nothing holds no nodes");
    }

    // --- Persistence across stacks ---

    /**
     * A relationship saved by one stack is read back by another, which is the round trip the
     * whole store exists for.
     *
     * @throws Exception
     */
    public function testARelationshipSurvivesAStackBoundary(): void
    {
        $this->seed($this->context(), "Persisted", ["One", "Two"]);

        $fresh = $this->context();
        /** @var AtomicBook $book */
        $book = $fresh->fetch(AtomicBook::fetchRequest())->first;

        $headings = $book->chapters->map(static fn(AtomicChapter $chapter): string => $chapter->heading)->array;
        sort($headings);
        $this->assertSame(["One", "Two"], $headings);
        $this->assertSame("Persisted", $book->chapters->first?->book?->title, "and the inverse resolves back to the owner");
    }
}
