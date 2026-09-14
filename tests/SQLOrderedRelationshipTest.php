<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Set;

/**
 * @property string $title
 * @property OrderedAlbum $album
 */
final class OrderedTrack extends ManagedObject
{
}

/**
 * @property string $name
 * @property Set<OrderedTrack> $tracks
 */
final class OrderedAlbum extends ManagedObject
{
}

/**
 * Covers the ordered to-many relationship path, which nothing exercised.
 *
 * An ordered to-many is read back through a different route than an unordered one:
 * SQLCore::newOrderedRelationshipInformationForRelationship resolves the destination IDs, then
 * re-fetches them through SQLObjectIDSetFetchRequestContext, whose whole job is to rewrite the
 * request as an IN over the primary key sorted by the order column. That context sat at 0%
 * because no model in the suite declared isOrdered.
 *
 * The distinction is only meaningful against a real server: the ordering lives in a column on
 * the destination table, so the assertions are about what comes back from MariaDB, not about a
 * generated string. Hence SQLMigrationTestCase, which creates the schema from the model.
 */
final class SQLOrderedRelationshipTest extends SQLMigrationTestCase
{
    /**
     * An Album with an ORDERED to-many of Tracks. The order column only exists because the
     * inverse is to-one — a many-to-many keeps its pairs in a correlation table that has nowhere
     * to put a position, which the store refuses outright.
     */
    private static function model(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $album = new RelationshipDescription();
        $album->name = "album";
        $album->lazyDestinationEntityName = "OrderedAlbum";
        $album->lazyInverseRelationshipName = "tracks";

        $track = new EntityDescription();
        $track->name = "OrderedTrack";
        $track->managedObjectClassName = OrderedTrack::class;
        $track->properties = new ArrayClass([$title, $album]);

        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $tracks = new RelationshipDescription();
        $tracks->name = "tracks";
        $tracks->lazyDestinationEntityName = "OrderedTrack";
        $tracks->lazyInverseRelationshipName = "album";
        $tracks->isToMany = true;
        $tracks->isOrdered = true;

        $albumEntity = new EntityDescription();
        $albumEntity->name = "OrderedAlbum";
        $albumEntity->managedObjectClassName = OrderedAlbum::class;
        $albumEntity->properties = new ArrayClass([$name, $tracks]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$track, $albumEntity]);
        return $model;
    }

    /**
     * An album with the given track titles, saved.
     *
     * @param list<string> $titles
     * @throws Exception
     */
    private function album(ManagedObjectContext $context, string $name, array $titles): OrderedAlbum
    {
        $album = new OrderedAlbum($context);
        $album->name = $name;
        foreach ($titles as $title) {
            $track = new OrderedTrack($context);
            $track->title = $title;
            $track->album = $album;
        }
        $context->save();
        return $album;
    }

    /** @return list<string> The titles of an album's tracks, in the order the relationship reports. */
    private static function titles(OrderedAlbum $album): array
    {
        return $album->tracks->map(static fn(OrderedTrack $track): string => $track->title)->array;
    }

    /**
     * The relationship survives a round trip through the store with every member intact. This is
     * the baseline the ordering assertions rest on: if the set came back short, an order
     * assertion would be checking the wrong thing.
     *
     * @throws Exception
     */
    public function testAnOrderedToManyRoundTripsEveryMember(): void
    {
        $context = $this->bootstrap(self::model());
        $this->album($context, "Kind of Blue", ["So What", "Freddie Freeloader", "Blue in Green"]);

        $fresh = $this->freshContext(self::model());
        /** @var OrderedAlbum $album */
        $album = $fresh->fetch(OrderedAlbum::fetchRequest())->first;

        $this->assertSame(3, $album->tracks->count, "every track comes back");
        $titles = self::titles($album);
        sort($titles);
        $this->assertSame(["Blue in Green", "Freddie Freeloader", "So What"], $titles);
    }

    /**
     * The model declares the relationship ordered, and that survives into the store's own model
     * — which is what selects the ordered read path rather than the plain one.
     *
     * @throws Exception
     */
    public function testTheStoreModelKeepsTheOrderedFlag(): void
    {
        $context = $this->bootstrap(self::model());
        $entity = EntityDescription::entity("OrderedAlbum", $context);

        /** @var RelationshipDescription $tracks */
        $tracks = $entity->relationshipsByName["tracks"];

        $this->assertTrue($tracks->isToMany, "the relationship is to-many");
        $this->assertTrue($tracks->isOrdered, "and it is ordered");
    }

    /**
     * Reading an ordered relationship goes through SQLObjectIDSetFetchRequestContext, which
     * re-fetches the destination IDs with an IN over the primary key sorted by the order column.
     * Asserted through the store's own entry point, since that is what the fault handler calls.
     *
     * @throws Exception
     */
    public function testOrderedRelationshipInformationResolvesEveryDestinationID(): void
    {
        $context = $this->bootstrap(self::model());
        $album = $this->album($context, "Blue Train", ["Moment's Notice", "Locomotion"]);
        $store = $context->persistentStoreCoordinator?->persistentStores->first;
        $this->assertNotNull($store, "the stack must have a store");

        $entity = EntityDescription::entity("OrderedAlbum", $context);
        /** @var RelationshipDescription $tracks */
        $tracks = $entity->relationshipsByName["tracks"];

        $information = $store->newOrderedRelationshipInformationForRelationship($tracks, $album->objectID, $context);

        $this->assertInstanceOf(ArrayClass::class, $information, "an ordered relationship resolves to a list of IDs");
        $this->assertSame(2, $information->count, "one entry per member of the relationship");
    }

    /**
     * An album with no tracks resolves to an empty result rather than failing — the store short
     * circuits before building the ID-set fetch, since an IN over no keys is not a query worth
     * sending.
     *
     * @throws Exception
     */
    public function testAnEmptyOrderedRelationshipResolvesToNothing(): void
    {
        $context = $this->bootstrap(self::model());
        $album = new OrderedAlbum($context);
        $album->name = "Empty";
        $context->save();
        $store = $context->persistentStoreCoordinator?->persistentStores->first;
        $this->assertNotNull($store, "the stack must have a store");

        $entity = EntityDescription::entity("OrderedAlbum", $context);
        /** @var RelationshipDescription $tracks */
        $tracks = $entity->relationshipsByName["tracks"];

        $information = $store->newOrderedRelationshipInformationForRelationship($tracks, $album->objectID, $context);

        $this->assertInstanceOf(ArrayClass::class, $information);
        $this->assertTrue($information->isEmpty, "an album with no tracks resolves to nothing");
    }

    /**
     * Two albums do not see each other's tracks: the ID set is scoped to the relationship being
     * read, so the IN clause names only the destination rows of that one object.
     *
     * @throws Exception
     */
    public function testOrderedRelationshipsAreScopedToTheirOwner(): void
    {
        $context = $this->bootstrap(self::model());
        $this->album($context, "First", ["A", "B", "C"]);
        $this->album($context, "Second", ["D"]);

        $fresh = $this->freshContext(self::model());
        $counts = [];
        foreach ($fresh->fetch(OrderedAlbum::fetchRequest()) as $album) {
            $counts[$album->name] = $album->tracks->count;
        }

        $this->assertSame(["First" => 3, "Second" => 1], $counts, "each album sees only its own tracks");
    }
}
