<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;

final class Warehouse extends ManagedObject
{
}

final class Crate extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over the to-many (with to-one inverse)
 * relationship family, which maps to SQLToMany. Unlike a many-to-many, this is backed by a
 * foreign-key column on the DESTINATION (to-one) side, created via the SQLToMany branch of
 * processTransformedEntityMappings (which materializes property->inverseToOne->foreignKey and
 * its index).
 *
 * Model: Warehouse -1----*- Crate. The to-many "crates" lives on Warehouse; its to-one inverse
 * "warehouse" lives on Crate, so the FK column "warehouseID" lands on the Crate table.
 */
final class SQLStoreMigratorToManyRelationshipTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        return $attribute;
    }

    /**
     * When $related is true, Warehouse gains a to-many "crates" and Crate gains the to-one
     * inverse "warehouse".
     */
    private static function model(bool $related): ManagedObjectModel
    {
        $warehouseProps = [self::attribute("code", AttributeType::string)];
        $crateProps = [self::attribute("label", AttributeType::string)];

        if ($related) {
            $crates = new RelationshipDescription();
            $crates->name = "crates";
            $crates->lazyDestinationEntityName = "Crate";
            $crates->lazyInverseRelationshipName = "warehouse";
            $crates->isToMany = true;
            $warehouseProps[] = $crates;

            $warehouse = new RelationshipDescription();
            $warehouse->name = "warehouse";
            $warehouse->lazyDestinationEntityName = "Warehouse";
            $warehouse->lazyInverseRelationshipName = "crates";
            $warehouse->maxCount = 1;
            $crateProps[] = $warehouse;
        }

        $warehouseEntity = new EntityDescription();
        $warehouseEntity->name = "Warehouse";
        $warehouseEntity->managedObjectClassName = Warehouse::class;
        $warehouseEntity->properties = new ArrayClass($warehouseProps);

        $crateEntity = new EntityDescription();
        $crateEntity->name = "Crate";
        $crateEntity->managedObjectClassName = Crate::class;
        $crateEntity->properties = new ArrayClass($crateProps);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$warehouseEntity, $crateEntity]);
        return $model;
    }

    public function testAddingAToManyRelationshipCreatesTheForeignKeyOnTheDestinationTable(): void
    {
        $context = $this->bootstrap(self::model(related: false));
        $crate = new Crate($context);
        $crate->label = "A1";
        $context->save();

        $this->assertFalse($this->hasColumn("Crate", "warehouseID"), "precondition: no FK column in v1");

        $migrated = $this->migrateTo(self::model(related: true));

        $this->assertTrue(
            $this->hasColumn("Crate", "warehouseID"),
            "the to-many's inverse to-one FK column lands on the destination (Crate) table",
        );
        $this->assertContains(
            "FK_Crate_Warehouse",
            $this->foreignKeyNames("Crate"),
            "the FK constraint is created on the destination table",
        );

        $rows = $migrated->fetch(Crate::fetchRequest());
        $this->assertCount(1, $rows, "the existing row survives adding the relationship");
        $this->assertSame("A1", (string)$rows->first()->label, "the pre-existing value is preserved");
    }
}
