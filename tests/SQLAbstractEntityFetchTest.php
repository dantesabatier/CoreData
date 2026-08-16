<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

abstract class TestPropertyBase extends ManagedObject
{
}

final class TestPropertyAttribute extends TestPropertyBase
{
}

final class TestPropertyRelationship extends TestPropertyBase
{
}

/**
 * Tests that a fetch targeting an abstract entity materializes each row as its concrete subentity class.
 *
 * The model mirrors the shape that exposed the bug: an abstract "Property" entity whose subentities
 * "Attribute" and "Relationship" map to different concrete classes. A fetch over "Property" must never
 * instantiate the abstract class — each row's entity column names the concrete subentity, so the row's
 * entity, not the request's, has to drive instantiation.
 *
 * Only the SQL store can exercise the buggy path: when a fetch reuses a cached query result whose
 * snapshots fall short of the serialization now being asked for, SQLCore re-faults the missing objects
 * and used to build their IDs from the request's (abstract) entity, so ManagedObjectContext tried to
 * instantiate the abstract class.
 */
final class SQLAbstractEntityFetchTest extends SQLMigrationTestCase
{
    /** The narrow shape, as the first view asks for it. */
    private const array NarrowShape = ["name" => AttributeType::string];

    /** The wide shape, as the view that also asks for the flags asks for it. */
    private const array WideShape = [
        "name" => AttributeType::string,
        "isOptional" => AttributeType::boolean,
    ];

    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $isOptional = new AttributeDescription();
        $isOptional->name = "isOptional";
        $isOptional->type = AttributeType::boolean;

        $property = new EntityDescription();
        $property->name = "Property";
        $property->managedObjectClassName = TestPropertyBase::class;
        $property->isAbstract = true;
        $property->properties = new ArrayClass([$name, $isOptional]);

        $attributeType = new AttributeDescription();
        $attributeType->name = "attributeType";
        $attributeType->type = AttributeType::string;

        $attribute = new EntityDescription();
        $attribute->name = "Attribute";
        $attribute->managedObjectClassName = TestPropertyAttribute::class;
        $attribute->superentity = $property;
        $attribute->properties = new ArrayClass([$attributeType]);

        $minCount = new AttributeDescription();
        $minCount->name = "minCount";
        $minCount->type = AttributeType::integer32;

        $relationship = new EntityDescription();
        $relationship->name = "Relationship";
        $relationship->managedObjectClassName = TestPropertyRelationship::class;
        $relationship->superentity = $property;
        $relationship->properties = new ArrayClass([$minCount]);

        $property->subentities = new ArrayClass([$attribute, $relationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$property]);
        return $model;
    }

    private function seed(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::model());
        $attribute = new TestPropertyAttribute($context);
        $attribute->name = "color";
        $attribute->isOptional = true;
        $attribute->attributeType = "string";
        $context->save();
        return $context;
    }

    /**
     * A fetch resolves its entity through the context associated with the running queue, so the
     * request has to be issued from inside one.
     *
     * @param array<string, AttributeType> $shape
     */
    private function fetch(ManagedObjectContext $context, array $shape): ManagedObject
    {
        $fetched = null;
        $context->performBlockAndWait(function () use ($context, $shape, &$fetched): void {
            $request = new FetchRequest("Property");
            $request->serialization = Dictionary::dictionaryWithArray($shape);
            $fetched = $context->fetch($request)->first;
        });
        return $fetched;
    }

    public function testAFetchOfAnAbstractEntityMaterializesTheConcreteSubentityClass(): void
    {
        $context = $this->seed();
        $this->fetch($context, self::NarrowShape);

        // A fresh context, like a fresh request: the object is unregistered there, so the wider fetch
        // reuses the store's cached query result, finds the snapshots too narrow, and re-faults the
        // row through the batch-fault path that used to instantiate the abstract class.
        $fresh = new ManagedObjectContext();
        $fresh->persistentStoreCoordinator = $context->persistentStoreCoordinator;
        $attribute = $this->fetch($fresh, self::WideShape);
        $fresh->persistentStoreCoordinator = null;

        $this->assertInstanceOf(TestPropertyAttribute::class, $attribute, "an abstract-entity fetch must materialize the concrete subentity class");
        $this->assertSame("color", $attribute->name);
        $this->assertTrue($attribute->isOptional);
        $this->assertSame("string", $attribute->attributeType);
    }

    public function testAnAbstractFetchReturnsEachConcreteSubentityInItsOwnClass(): void
    {
        $context = $this->seed();
        $context->performBlockAndWait(function () use ($context): void {
            $relationship = new TestPropertyRelationship($context);
            $relationship->name = "members";
            $relationship->isOptional = false;
            $relationship->minCount = 1;
            $context->save();
        });

        $properties = null;
        $context->performBlockAndWait(function () use ($context, &$properties): void {
            $properties = $context->fetch(new FetchRequest("Property"));
        });

        $this->assertCount(2, $properties);
        $this->assertTrue($properties->contains(fn(ManagedObject $object): bool => $object instanceof TestPropertyAttribute), "an abstract-entity fetch must include its Attribute subentities");
        $this->assertTrue($properties->contains(fn(ManagedObject $object): bool => $object instanceof TestPropertyRelationship), "an abstract-entity fetch must include its Relationship subentities");
    }
}
