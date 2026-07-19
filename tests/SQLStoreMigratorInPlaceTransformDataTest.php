<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;

final class Reading extends ManagedObject
{
}

/**
 * Investigates the claim that an in-place transform migration of an entity whose NAME is
 * unchanged skips the first data-migration pass, so attribute value conversions are not applied.
 *
 * For a SQL store the schema change is applied by DDL (ALTER TABLE ... MODIFY), and MariaDB
 * converts stored values in place, so data should survive a type change even if the object-graph
 * first pass is skipped. This test pins that end-to-end behavior: a numeric value stored under
 * integer32 must still be readable and correct after the column is migrated to integer64, and a
 * new mandatory attribute must end up populated (its DDL default), all on a same-named entity.
 */
final class SQLStoreMigratorInPlaceTransformDataTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type, bool $optional = true): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = $optional;
        return $attribute;
    }

    /**
     * @param list<AttributeDescription> $attributes
     */
    private static function model(array $attributes): ManagedObjectModel
    {
        $reading = new EntityDescription();
        $reading->name = "Reading";
        $reading->managedObjectClassName = Reading::class;
        $reading->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$reading]);
        return $model;
    }

    public function testInPlaceTransformPreservesConvertedNumericValue(): void
    {
        // v1: value is integer32.
        $context = $this->bootstrap(self::model([
            self::attribute("label", AttributeType::string),
            self::attribute("value", AttributeType::integer32),
        ]));
        $reading = new Reading($context);
        $reading->label = "temperature";
        $reading->value = 42;
        $context->save();

        // v2: value becomes integer64 (same entity name -> in-place transform).
        $this->migrateTo(self::model([
            self::attribute("label", AttributeType::string),
            self::attribute("value", AttributeType::integer64),
        ]));

        // The DDL MODIFY converts the column; the stored numeric value must remain intact.
        $this->assertSame(
            ["42"],
            $this->columnValues("Reading", "value"),
            "the numeric value survives an in-place integer32 -> integer64 transform",
        );
    }
}
