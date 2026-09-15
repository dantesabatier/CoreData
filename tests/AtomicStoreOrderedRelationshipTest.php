<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
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
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property string $name
 * @property Set<AtomicOrderedTrack> $tracks
 */
final class AtomicOrderedAlbum extends ManagedObject
{
}

/**
 * @property string $heading
 * @property AtomicOrderedAlbum|null $album
 */
final class AtomicOrderedTrack extends ManagedObject
{
}

/**
 * Covers how an atomic store resolves an ORDERED to-many.
 *
 * A plain to-many is answered by scanning the cache nodes and the resulting order is whatever
 * the scan produced. An ordered one takes a second step: an atomic store has no position column
 * of its own — the SQL store keeps one, this family does not — so it sorts by the first
 * attribute the destination entity declares. Nothing in the model marks that attribute as the
 * ordering key, which is why it has to be pinned by a test rather than read off the declaration.
 *
 * The fixture is built so insertion order and sorted order disagree: the headings are inserted
 * "gamma", "alpha", "beta" and must come back alphabetically. Album and Track also name their
 * first attribute differently ("name" against "heading"), so a sort key taken from the wrong
 * side of the relationship fails outright instead of passing by coincidence.
 *
 * Exercised through XMLObjectStore, since AtomicStore is abstract and MemoryObjectStore has no
 * load(). Each test gets its own temporary file.
 */
final class AtomicStoreOrderedRelationshipTest extends TestCase
{
    private URL $storeURL;

    /**
     * Album -1----*- Track, with the to-many marked ordered. "heading" is the first attribute
     * Track declares, so it is the key the store sorts by.
     */
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $tracks = new RelationshipDescription();
        $tracks->name = "tracks";
        $tracks->lazyDestinationEntityName = "AtomicOrderedTrack";
        $tracks->lazyInverseRelationshipName = "album";
        $tracks->isToMany = true;
        $tracks->isOrdered = true;

        $album = new EntityDescription();
        $album->name = "AtomicOrderedAlbum";
        $album->managedObjectClassName = AtomicOrderedAlbum::class;
        $album->properties = new ArrayClass([$name, $tracks]);

        $heading = new AttributeDescription();
        $heading->name = "heading";
        $heading->type = AttributeType::string;

        $albumRelationship = new RelationshipDescription();
        $albumRelationship->name = "album";
        $albumRelationship->lazyDestinationEntityName = "AtomicOrderedAlbum";
        $albumRelationship->lazyInverseRelationshipName = "tracks";

        $track = new EntityDescription();
        $track->name = "AtomicOrderedTrack";
        $track->managedObjectClassName = AtomicOrderedTrack::class;
        $track->properties = new ArrayClass([$heading, $albumRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$album, $track]);
        return $model;
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

    /**
     * A fresh stack over this test's own store file.
     *
     * @throws Exception
     */
    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * An album carrying tracks with the given headings, saved.
     *
     * @param list<string> $headings
     * @throws Exception
     */
    private function seed(array $headings): void
    {
        $context = $this->context();
        $album = new AtomicOrderedAlbum($context);
        $album->name = "record";
        foreach ($headings as $heading) {
            $track = new AtomicOrderedTrack($context);
            $track->heading = $heading;
            $track->album = $album;
        }
        $context->save();
    }

    /**
     * The headings the store resolves for the album's ordered relationship, in the order it
     * returns them.
     *
     * Asks the store directly rather than reading the relationship off the object: the context
     * drops the ordered result into a Set on its way to the fault, and a Set keeps no order, so
     * the object's own view of the relationship cannot show what this method returned.
     *
     * @return list<string>
     * @throws Exception
     */
    private function resolvedHeadings(): array
    {
        $context = $this->context();
        $albums = $context->fetch(AtomicOrderedAlbum::fetchRequest());
        $album = $albums->first;
        $this->assertInstanceOf(AtomicOrderedAlbum::class, $album, "the seeded album must come back");
        $store = $context->persistentStoreCoordinator?->persistentStores->first;
        $this->assertInstanceOf(PersistentStore::class, $store, "the stack must have a store");
        /** @var RelationshipDescription $tracks */
        $tracks = EntityDescription::entity("AtomicOrderedAlbum", $context)->relationshipsByName["tracks"];

        $information = $store->newOrderedRelationshipInformationForRelationship($tracks, $album->objectID, $context);
        $this->assertInstanceOf(ArrayClass::class, $information, "an ordered relationship resolves to a list of IDs");

        $headings = [];
        foreach ($information as $objectID) {
            $headings[] = (string)$context->object($objectID)->heading;
        }
        return $headings;
    }

    /**
     * The sort key is the first attribute of the entity the relationship points AT, so the
     * tracks come back ordered by their own heading rather than in insertion order.
     *
     * Reading it off the owner entity instead would ask a track node for an attribute only the
     * album declares, and a cache node answers only for its own entity's properties — which
     * raises UndefinedKeyException unless both entities happen to name their first attribute the
     * same. That is why the fixture deliberately names them differently.
     *
     * @throws Exception
     */
    public function testAnOrderedToManyIsSortedByTheDestinationsFirstAttribute(): void
    {
        $this->seed(["gamma", "alpha", "beta"]);

        $this->assertSame(["alpha", "beta", "gamma"], $this->resolvedHeadings(), "the ordered relationship is sorted by heading");
    }

    /**
     * And the ordering holds whatever order the objects were created in: seeding the reverse way
     * produces the same result, so what decides it is the sort key and not the insertion.
     *
     * @throws Exception
     */
    public function testTheOrderDoesNotDependOnTheInsertionOrder(): void
    {
        $this->seed(["beta", "gamma", "alpha"]);

        $this->assertSame(["alpha", "beta", "gamma"], $this->resolvedHeadings(), "a different insertion order resolves to the same sorted order");
    }
}
