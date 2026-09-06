<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\CompositeAttributeDescription;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * @property string $name
 * @property Dictionary<float> $position
 */
final class Marker extends ManagedObject
{
}

/**
 * @property int $x
 */
final class Waypoint extends ManagedObject
{
}

/**
 * Round-trip tests for composite attributes read back out of the SQL store.
 *
 * A composite has no column of its own: it decomposes into one physical column per element,
 * so a row arrives from the database as flat columns ("x", "y") with no trace of the composite
 * that owns them. SQLFetchRequestContext rebuilds the nesting by resolving each column name
 * through compositeAttributeNameToSQLProperty, which maps an element name back to the
 * composite's SQLAttribute — and therefore to the composite's own description.
 *
 * Regression guard: that description is a CompositeAttributeDescription whose type is
 * AttributeType::compositeAttributeType, while the value being read is an element's scalar.
 * Coercing the scalar against the composite's type hits
 * "compositeAttributeType => $value instanceof Dictionary ? $value : new Dictionary()", which
 * silently replaces the number with an empty Dictionary — every element of every composite
 * reading back as {"x":[],"y":[]} while the columns still held the right values. The write
 * path was never affected, which is what made it look like data loss rather than a read bug.
 * The read path must therefore coerce each element against ITS OWN description.
 *
 * The assertions read through a fresh stack rather than the context that did the writing, so a
 * pass means the value genuinely came back out of the database and not from an object the
 * context still had registered.
 *
 * Note on fixture shape: a composite is assigned after the owning object's first save. Setting
 * one in the same change event as the insert lets the snapshot that initializes the new object
 * overwrite it with the elements' defaults — a property of object initialization, unrelated to
 * the read path under test here.
 */
final class SQLCompositeAttributeFetchTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type, bool $optional = false): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = $optional;
        return $attribute;
    }

    /** A Marker with a name and a position{x, y} composite of doubles. */
    private static function markerModel(): ManagedObjectModel
    {
        $position = new CompositeAttributeDescription();
        $position->name = "position";
        $position->type = AttributeType::compositeAttributeType;
        $position->elements = new ArrayClass([
            self::attribute("x", AttributeType::double, optional: true),
            self::attribute("y", AttributeType::double, optional: true),
        ]);

        $entity = new EntityDescription();
        $entity->name = "Marker";
        $entity->managedObjectClassName = Marker::class;
        $entity->properties = new ArrayClass([
            self::attribute("name", AttributeType::string),
            $position,
        ]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    /**
     * @throws Exception
     */
    public function testCompositeElementsSurviveTheRoundTripAsNumbers(): void
    {
        $context = $this->bootstrap(self::markerModel());
        $marker = new Marker($context);
        $marker->name = "Base";
        $context->save();
        $marker->position = Dictionary::dictionaryWithArray(["x" => 2510.0, "y" => 63.0]);
        $context->save();

        // The columns hold the values, so a failure below is a read bug rather than a lost write.
        $this->assertSame(["2510"], $this->columnValues("Marker", "x"), "the x element reaches its column");
        $this->assertSame(["63"], $this->columnValues("Marker", "y"), "the y element reaches its column");

        $fetched = $this->freshContext(self::markerModel())->fetch(Marker::fetchRequest())->first;
        $this->assertInstanceOf(Marker::class, $fetched);

        $position = $fetched->position;
        $this->assertInstanceOf(Dictionary::class, $position, "a composite materializes as a Dictionary");
        $this->assertEqualsWithDelta(
            2510.0,
            $position["x"],
            0.0001,
            "the element is coerced against its own description; coercing it against the composite's type replaces it with an empty Dictionary",
        );
        $this->assertEqualsWithDelta(63.0, $position["y"], 0.0001, "every element survives, not just the first");
    }

    /**
     * @throws Exception
     */
    public function testCompositeSurvivesWhenAnotherEntityHasAPlainAttributeOfTheSameName(): void
    {
        // Waypoint.x is an ordinary attribute whose name collides with Marker's composite element. Element names resolve per entity, so neither may read back as the other's type.
        $waypoint = new EntityDescription();
        $waypoint->name = "Waypoint";
        $waypoint->managedObjectClassName = Waypoint::class;
        $waypoint->properties = new ArrayClass([
            self::attribute("x", AttributeType::integer64),
        ]);

        $model = self::markerModel();
        $model->entities = $model->entities->appending($waypoint);

        $context = $this->bootstrap($model);
        $marker = new Marker($context);
        $marker->name = "Base";
        $point = new Waypoint($context);
        $point->x = 7;
        $context->save();
        $marker->position = Dictionary::dictionaryWithArray(["x" => 1.5, "y" => -2.5]);
        $context->save();

        $fresh = $this->freshContext($model);
        $fetchedMarker = $fresh->fetch(Marker::fetchRequest())->first;
        $this->assertInstanceOf(Marker::class, $fetchedMarker);
        $this->assertEqualsWithDelta(1.5, $fetchedMarker->position["x"], 0.0001, "the composite element keeps its own type");
        $this->assertEqualsWithDelta(-2.5, $fetchedMarker->position["y"], 0.0001);

        $fetchedPoint = $fresh->fetch(Waypoint::fetchRequest())->first;
        $this->assertInstanceOf(Waypoint::class, $fetchedPoint);
        $this->assertSame(7, $fetchedPoint->x, "the plain attribute of the same name is unaffected");
    }

    /**
     * @throws Exception
     */
    public function testEveryRowKeepsItsOwnCompositeValues(): void
    {
        // One row reading correctly proves little if the rebuilt Dictionary is shared or overwritten across rows, which is how the bug presented: a graph of many entities collapsing onto a single position.
        $context = $this->bootstrap(self::markerModel());
        $expected = ["First" => [10.0, 20.0], "Second" => [30.0, 40.0], "Third" => [50.0, 60.0]];
        foreach ($expected as $name => $coordinates) {
            $marker = new Marker($context);
            $marker->name = $name;
            $context->save();
            $marker->position = Dictionary::dictionaryWithArray(["x" => $coordinates[0], "y" => $coordinates[1]]);
        }
        $context->save();

        $markers = $this->freshContext(self::markerModel())->fetch(Marker::fetchRequest());
        $this->assertCount(3, $markers);
        foreach ($markers as $marker) {
            $this->assertInstanceOf(Marker::class, $marker);
            [$x, $y] = $expected[$marker->name];
            $this->assertEqualsWithDelta($x, $marker->position["x"], 0.0001, "$marker->name keeps its own x");
            $this->assertEqualsWithDelta($y, $marker->position["y"], 0.0001, "$marker->name keeps its own y");
        }
    }

    /**
     * @throws Exception
     */
    public function testAnUnsetCompositeReadsBackEmptyRatherThanFabricatingValues(): void
    {
        $context = $this->bootstrap(self::markerModel());
        $marker = new Marker($context);
        $marker->name = "Unplaced";
        $context->save();

        $fetched = $this->freshContext(self::markerModel())->fetch(Marker::fetchRequest())->first;
        $this->assertInstanceOf(Marker::class, $fetched);
        $this->assertNull($fetched->position["x"], "an element never written stays absent");
        $this->assertNull($fetched->position["y"]);
    }
}
