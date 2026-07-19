<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;

final class Gadget extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over the simplest mapping family: attribute
 * changes on a single entity (transformEntityMappingType). These pin the behavior that
 * already works today so later refactors and bug fixes cannot regress it.
 *
 * Each test bootstraps a v1 schema, inserts a row, migrates to a v2 model, then asserts on
 * both the resulting DDL (INFORMATION_SCHEMA) and the survival of the row.
 */
final class SQLStoreMigratorAttributeTest extends SQLMigrationTestCase
{
    /**
     * A one-entity model whose attribute set is assembled by the caller, so each test can
     * describe exactly the v1/v2 shapes it needs.
     *
     * @param list<AttributeDescription> $attributes
     */
    private static function gadgetModel(array $attributes): ManagedObjectModel
    {
        $gadget = new EntityDescription();
        $gadget->name = "Gadget";
        $gadget->managedObjectClassName = Gadget::class;
        $gadget->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$gadget]);
        return $model;
    }

    private static function attribute(string $name, AttributeType $type, bool $optional = false): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = $optional;
        return $attribute;
    }

    public function testAddingAnOptionalAttributeAddsAColumnAndPreservesData(): void
    {
        $context = $this->bootstrap(self::gadgetModel([
            self::attribute("name", AttributeType::string),
        ]));
        $gadget = new Gadget($context);
        $gadget->name = "alpha";
        $context->save();

        $this->assertFalse($this->hasColumn("Gadget", "color"), "precondition: v1 has no color column");

        $migrated = $this->migrateTo(self::gadgetModel([
            self::attribute("name", AttributeType::string),
            self::attribute("color", AttributeType::string, optional: true),
        ]));

        $this->assertTrue($this->hasColumn("Gadget", "color"), "the added attribute becomes a new column");

        $rows = $migrated->fetch(Gadget::fetchRequest());
        $this->assertCount(1, $rows, "the existing row survives the migration");
        $this->assertSame("alpha", (string)$rows->first()->name, "the pre-existing value is preserved");
    }

    public function testAddedRequiredAttributeColumnIsNotNullable(): void
    {
        $this->bootstrap(self::gadgetModel([
            self::attribute("name", AttributeType::string),
        ]));

        $this->migrateTo(self::gadgetModel([
            self::attribute("name", AttributeType::string),
            self::attribute("count", AttributeType::integer32),
        ]));

        $this->assertTrue($this->hasColumn("Gadget", "count"), "the required attribute is added as a column");
        $this->assertFalse(
            (bool)$this->columnIsNullable("Gadget", "count"),
            "a non-optional attribute maps to a NOT NULL column",
        );
    }

    public function testAddedOptionalAttributeColumnIsNullable(): void
    {
        $this->bootstrap(self::gadgetModel([
            self::attribute("name", AttributeType::string),
        ]));

        $this->migrateTo(self::gadgetModel([
            self::attribute("name", AttributeType::string),
            self::attribute("color", AttributeType::string, optional: true),
        ]));

        $this->assertTrue(
            (bool)$this->columnIsNullable("Gadget", "color"),
            "an optional attribute maps to a NULL-able column",
        );
    }

    public function testWideningAttributeTypeIsAppliedToTheColumn(): void
    {
        $context = $this->bootstrap(self::gadgetModel([
            self::attribute("name", AttributeType::string),
            self::attribute("size", AttributeType::integer32),
        ]));
        $gadget = new Gadget($context);
        $gadget->name = "beta";
        $gadget->size = 7;
        $context->save();

        $this->assertSame("int", $this->columnType("Gadget", "size"), "precondition: integer32 is an INT column");

        $migrated = $this->migrateTo(self::gadgetModel([
            self::attribute("name", AttributeType::string),
            self::attribute("size", AttributeType::integer64),
        ]));

        $this->assertSame(
            "bigint",
            $this->columnType("Gadget", "size"),
            "widening integer32 -> integer64 modifies the column to BIGINT",
        );

        $rows = $migrated->fetch(Gadget::fetchRequest());
        $this->assertCount(1, $rows, "the row survives a type change");
        $this->assertSame(7, (int)$rows->first()->size, "the numeric value is preserved across the type change");
    }
}
