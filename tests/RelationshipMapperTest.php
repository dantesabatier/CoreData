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
use Sabatier\CoreData\ManagedObjectIDResolver;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\ManagedObjectResolver;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\RelationshipMapper;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/** @property string $name */
final class MapperOwner extends ManagedObject
{
}

/** @property string $tag */
final class MapperItem extends ManagedObject
{
}

/**
 * Tests for RelationshipMapper, which decides what an incoming relationship value means on its
 * way into the object graph.
 *
 * It is a type-dispatch table with an unusual property: three of its branches end in
 * fatal_error. That is the reason to cover it. The accepting branches are exercised constantly
 * by every other suite, but the rejecting ones only run when a caller passes something the graph
 * cannot hold — and a dispatch table that silently accepts what it should reject writes garbage
 * into a relationship, while one that rejects what it should accept breaks a legitimate save.
 * Neither shows up until it does.
 *
 * The distinction the tests turn on is cardinality: a collection is only valid for a to-many
 * relationship, and a single object only makes sense for either side, so the same value can be
 * accepted or refused depending on the relationship it arrives for.
 *
 * ManagedObjectResolver is final and not an interface, so it is built for real over an XML-backed
 * stack rather than stubbed; the mapper's own behaviour is what is asserted, and resolution is
 * left to do its actual job.
 */
final class RelationshipMapperTest extends TestCase
{
    private URL $storeURL;
    private ManagedObjectContext $context;
    private PersistentStore $store;

    /** An Owner with a to-many `items`, and the Item's to-one inverse. */
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $items = new RelationshipDescription();
        $items->name = "items";
        $items->lazyDestinationEntityName = "MapperItem";
        $items->lazyInverseRelationshipName = "owner";
        $items->isToMany = true;
        $items->isOptional = true;

        $owner = new EntityDescription();
        $owner->name = "MapperOwner";
        $owner->managedObjectClassName = MapperOwner::class;
        $owner->properties = new ArrayClass([$name, $items]);

        $tag = new AttributeDescription();
        $tag->name = "tag";
        $tag->type = AttributeType::string;

        $ownerRelationship = new RelationshipDescription();
        $ownerRelationship->name = "owner";
        $ownerRelationship->lazyDestinationEntityName = "MapperOwner";
        $ownerRelationship->lazyInverseRelationshipName = "items";
        $ownerRelationship->isOptional = true;

        $item = new EntityDescription();
        $item->name = "MapperItem";
        $item->managedObjectClassName = MapperItem::class;
        $item->properties = new ArrayClass([$tag, $ownerRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$owner, $item]);
        return $model;
    }

    /** The mapper under test, wired the way SnapshotMapper wires it. */
    private function mapper(): RelationshipMapper
    {
        return new RelationshipMapper(new ManagedObjectResolver(
            $this->context,
            new ManagedObjectIDResolver($this->store, $this->context),
        ));
    }

    private function relationship(string $entityName, string $name): RelationshipDescription
    {
        $relationship = self::model()->entitiesByName[$entityName]?->relationshipsByName[$name];
        self::assertInstanceOf(RelationshipDescription::class, $relationship);
        return $relationship;
    }

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");

        $coordinator = new PersistentStoreCoordinator(self::model());
        $this->store = $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;
    }

    /** @throws Exception */
    #[Override]
    protected function tearDown(): void
    {
        $this->context->persistentStoreCoordinator = null;
        FileManager::default()->removeItem($this->storeURL);
    }

    // --- What the mapper accepts ---

    /**
     * The ordinary to-many case: a collection of managed objects maps to the same objects,
     * resolved through the resolver.
     */
    public function testACollectionMapsOntoAToManyRelationship(): void
    {
        $owner = new MapperOwner($this->context);
        $owner->name = "O-1";
        $first = new MapperItem($this->context);
        $first->tag = "A";
        $second = new MapperItem($this->context);
        $second->tag = "B";

        $mapped = new Dictionary();
        $this->mapper()->process($owner, $mapped, "items", new ArrayClass([$first, $second]), $this->relationship("MapperOwner", "items"));

        $this->assertInstanceOf(ArrayClass::class, $mapped["items"]);
        $this->assertSame(2, $mapped["items"]->count, "every element is carried over");
    }

    /**
     * A Set is accepted wherever an ArrayClass is: the to-many branch tests for either, because
     * a to-many relationship is unordered unless the model says otherwise.
     */
    public function testASetIsAcceptedForAToManyRelationship(): void
    {
        $owner = new MapperOwner($this->context);
        $owner->name = "O-1";
        $item = new MapperItem($this->context);
        $item->tag = "A";

        $mapped = new Dictionary();
        $this->mapper()->process($owner, $mapped, "items", new Set([$item]), $this->relationship("MapperOwner", "items"));

        $this->assertSame(1, $mapped["items"]->count);
    }

    /**
     * A single managed object maps onto a to-one relationship. This is the branch every ordinary
     * save takes.
     */
    public function testASingleObjectMapsOntoAToOneRelationship(): void
    {
        $item = new MapperItem($this->context);
        $item->tag = "A";
        $owner = new MapperOwner($this->context);
        $owner->name = "O-1";

        $mapped = new Dictionary();
        $this->mapper()->process($item, $mapped, "owner", $owner, $this->relationship("MapperItem", "owner"));

        $this->assertSame($owner, $mapped["owner"], "the object passes through resolution unchanged");
    }

    /**
     * Nil is how the framework spells "explicitly no value", as distinct from a key that was
     * never mentioned. It is stored as-is rather than resolved, so clearing a relationship
     * survives the trip through the mapper.
     */
    public function testNilIsStoredAsAnExplicitEmptyValue(): void
    {
        $item = new MapperItem($this->context);
        $item->tag = "A";

        $mapped = new Dictionary();
        $nil = Nil::nil();
        $this->mapper()->process($item, $mapped, "owner", $nil, $this->relationship("MapperItem", "owner"));

        $this->assertSame($nil, $mapped["owner"], "Nil is preserved, not resolved into an object");
    }

    /**
     * An empty collection arriving for a to-ONE relationship is tolerated in silence: there is
     * nothing to insert, so there is nothing to reject. Only a non-empty one is an error, which
     * is the distinction the next test covers.
     */
    public function testAnEmptyCollectionForAToOneRelationshipIsIgnored(): void
    {
        $item = new MapperItem($this->context);
        $item->tag = "A";

        $mapped = new Dictionary();
        $this->mapper()->process($item, $mapped, "owner", new ArrayClass(), $this->relationship("MapperItem", "owner"));

        $this->assertTrue($mapped->isEmpty, "nothing was mapped, and nothing was raised");
    }

    // --- What the mapper refuses ---

    /**
     * A non-empty collection for a to-one relationship is a cardinality error: the graph has one
     * slot and the caller supplied several. Accepting it would silently keep one and drop the
     * rest.
     */
    public function testANonEmptyCollectionForAToOneRelationshipIsRejected(): void
    {
        $item = new MapperItem($this->context);
        $item->tag = "A";
        $owner = new MapperOwner($this->context);
        $owner->name = "O-1";

        $mapped = new Dictionary();

        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/unsupported value of type/");
        $this->mapper()->process($item, $mapped, "owner", new ArrayClass([$owner]), $this->relationship("MapperItem", "owner"));
    }

    /**
     * A scalar is never a relationship value, whichever side it arrives for. The message names
     * the entity and the key, because a mapper failure otherwise says nothing about where the
     * bad value came from.
     */
    public function testAScalarIsRejectedForARelationship(): void
    {
        $item = new MapperItem($this->context);
        $item->tag = "A";

        $mapped = new Dictionary();

        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/MapperItem.*unsupported value of type.*owner/");
        $this->mapper()->process($item, $mapped, "owner", "not-an-object", $this->relationship("MapperItem", "owner"));
    }

    /**
     * A plain PHP null is NOT the same as Nil here: Nil is the explicit empty value and passes,
     * null falls through to the rejecting branch. Worth pinning because the two read alike at a
     * call site and only one of them is supported.
     */
    public function testAPlainNullIsRejectedWhereNilIsAccepted(): void
    {
        $item = new MapperItem($this->context);
        $item->tag = "A";

        $mapped = new Dictionary();

        $this->expectException(InternalInconsistencyException::class);
        $this->mapper()->process($item, $mapped, "owner", null, $this->relationship("MapperItem", "owner"));
    }

    /**
     * An integer for a to-many relationship takes the same rejecting branch as any other scalar:
     * the collection test comes first, so a non-collection never reaches the to-many handling.
     */
    public function testAScalarIsRejectedForAToManyRelationship(): void
    {
        $owner = new MapperOwner($this->context);
        $owner->name = "O-1";

        $mapped = new Dictionary();

        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/MapperOwner.*items/");
        $this->mapper()->process($owner, $mapped, "items", 42, $this->relationship("MapperOwner", "items"));
    }
}
