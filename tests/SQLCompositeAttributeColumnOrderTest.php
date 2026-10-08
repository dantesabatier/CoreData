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
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;

final class Beacon extends ManagedObject
{
}

final class Route extends ManagedObject
{
}

/**
 * Where a composite attribute's element columns land in the table.
 *
 * A composite has no column of its own, so its elements stand in for it. They used to be appended after every other column, which put them behind the foreign keys that close the table, both when the table was created and when a migration added the composite to an existing one. They belong where the composite sits among the attributes, ahead of the foreign keys.
 */
final class SQLCompositeAttributeColumnOrderTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type, bool $optional = false): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = $optional;
        return $attribute;
    }

    /** A Beacon with a name, an optional position{x, y} composite, and a to-one Route; the Route holds the to-many inverse. */
    private static function model(bool $includesPosition): ManagedObjectModel
    {
        $route = new RelationshipDescription();
        $route->name = "route";
        $route->lazyDestinationEntityName = "Route";
        $route->lazyInverseRelationshipName = "beacons";
        $route->isOptional = true;

        $beaconProperties = new ArrayClass([self::attribute("name", AttributeType::string)]);
        if ($includesPosition) {
            $position = new CompositeAttributeDescription();
            $position->name = "position";
            $position->type = AttributeType::compositeAttributeType;
            $position->elements = new ArrayClass([
                self::attribute("x", AttributeType::double, optional: true),
                self::attribute("y", AttributeType::double, optional: true),
            ]);
            $beaconProperties->append($position);
        }
        $beaconProperties->append($route);
        $beacon = new EntityDescription();
        $beacon->name = "Beacon";
        $beacon->managedObjectClassName = Beacon::class;
        $beacon->properties = $beaconProperties;

        $beacons = new RelationshipDescription();
        $beacons->name = "beacons";
        $beacons->lazyDestinationEntityName = "Beacon";
        $beacons->lazyInverseRelationshipName = "route";
        $beacons->isToMany = true;
        $beacons->isOptional = true;

        $routeEntity = new EntityDescription();
        $routeEntity->name = "Route";
        $routeEntity->managedObjectClassName = Route::class;
        $routeEntity->properties = new ArrayClass([self::attribute("name", AttributeType::string), $beacons]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$beacon, $routeEntity]);
        return $model;
    }

    /**
     * @throws Exception
     */
    public function testACreatedTablePutsTheCompositeAheadOfTheForeignKeys(): void
    {
        $this->bootstrap(self::model(includesPosition: true));

        $this->assertSame(["objectID", "entityName", "version", "name", "x", "y", "routeID"], $this->columnNames("Beacon"));
    }

    /**
     * @throws Exception
     */
    public function testAMigrationPutsAnAddedCompositeAheadOfTheForeignKeys(): void
    {
        $this->bootstrap(self::model(includesPosition: false));
        $this->migrateTo(self::model(includesPosition: true));

        $this->assertSame(["objectID", "entityName", "version", "name", "x", "y", "routeID"], $this->columnNames("Beacon"));
    }
}
