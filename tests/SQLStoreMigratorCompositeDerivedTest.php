<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\CompositeAttributeDescription;
use Sabatier\CoreData\DerivedAttributeDescription;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;

final class Doc extends ManagedObject
{
}

final class Place extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over the two most intricate attribute kinds.
 *
 * Derived attributes (DerivedAttributeDescription): a deterministic derivation persists as a
 * generated column ("... GENERATED ALWAYS AS (expr) STORED"), so adding one during migration
 * both creates the column and back-computes it over existing rows.
 *
 * Composite attributes (CompositeAttributeDescription): a composite has no column of its own;
 * it decomposes into one physical column per element (verified: coordinate{latitude,longitude}
 * -> columns "latitude","longitude"). Adding a composite during migration must create every
 * element column, which exercises the migrator's byMappingByCompositeNameAssociationTable path.
 */
final class SQLStoreMigratorCompositeDerivedTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type, bool $optional = false): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = $optional;
        return $attribute;
    }

    /**
     * @param class-string<ManagedObject> $class
     * @param list<AttributeDescription> $attributes
     */
    private static function model(string $entityName, string $class, array $attributes): ManagedObjectModel
    {
        $entity = new EntityDescription();
        $entity->name = $entityName;
        $entity->managedObjectClassName = $class;
        $entity->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    public function testAddingADerivedAttributeCreatesAGeneratedColumnComputedOverExistingData(): void
    {
        // v1: Doc.title only.
        $context = $this->bootstrap(self::model("Doc", Doc::class, [
            self::attribute("title", AttributeType::string),
        ]));
        $doc = new Doc($context);
        $doc->title = "hello";
        $context->save();

        $this->assertFalse($this->hasColumn("Doc", "upperTitle"), "precondition: no derived column in v1");

        // v2: add derived upperTitle = UPPER(title).
        $upper = new DerivedAttributeDescription();
        $upper->name = "upperTitle";
        $upper->type = AttributeType::string;
        $upper->isOptional = true;
        $upper->derivationExpression = Expression::expressionForFunction(
            "uppercase:",
            new ArrayClass([Expression::expressionForKeyPath("title")]),
        );

        $this->migrateTo(self::model("Doc", Doc::class, [
            self::attribute("title", AttributeType::string),
            $upper,
        ]));

        $this->assertTrue($this->hasColumn("Doc", "upperTitle"), "the derived attribute becomes a column");
        $this->assertSame(
            "STORED GENERATED",
            $this->columnExtra("Doc", "upperTitle"),
            "a deterministic derived attribute persists as a generated (STORED) column",
        );
        $this->assertSame(
            ["HELLO"],
            $this->columnValues("Doc", "upperTitle"),
            "the generated column is back-computed over the pre-existing row",
        );
    }

    public function testAddingACompositeAttributeCreatesAColumnPerElement(): void
    {
        // v1: Place.name only.
        $context = $this->bootstrap(self::model("Place", Place::class, [
            self::attribute("name", AttributeType::string),
        ]));
        $place = new Place($context);
        $place->name = "Base";
        $context->save();

        $this->assertFalse($this->hasColumn("Place", "latitude"), "precondition: no element columns in v1");
        $this->assertFalse($this->hasColumn("Place", "longitude"), "precondition: no element columns in v1");

        // v2: add composite coordinate{latitude, longitude}.
        $coordinate = new CompositeAttributeDescription();
        $coordinate->name = "coordinate";
        $coordinate->elements = new ArrayClass([
            self::attribute("latitude", AttributeType::double, optional: true),
            self::attribute("longitude", AttributeType::double, optional: true),
        ]);

        $this->migrateTo(self::model("Place", Place::class, [
            self::attribute("name", AttributeType::string),
            $coordinate,
        ]));

        $this->assertTrue($this->hasColumn("Place", "latitude"), "the composite's first element becomes a column");
        $this->assertTrue($this->hasColumn("Place", "longitude"), "the composite's second element becomes a column");
        $this->assertFalse(
            $this->hasColumn("Place", "coordinate"),
            "the composite itself has no column of its own — only its elements do",
        );

        $this->assertSame(
            ["Base"],
            $this->columnValues("Place", "name"),
            "the existing row survives adding a composite attribute",
        );
    }

    /**
     * Returns the EXTRA flag (e.g. "STORED GENERATED", "VIRTUAL GENERATED", "auto_increment")
     * of a column, or null if it is absent.
     */
    private function columnExtra(string $tableName, string $columnName): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        );
        $stmt->execute([static::DATABASE_NAME, $tableName, $columnName]);
        $extra = $stmt->fetchColumn();
        return $extra === false ? null : strtoupper((string)$extra);
    }
}
