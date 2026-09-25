<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;

/**
 * @property string $color
 * @property string $colour
 */
final class Gizmo extends ManagedObject
{
}

/**
 * @property string $color
 */
final class Contraption extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over renames. A rename is distinguished from a
 * drop+add by a shared renamingIdentifier: MappingModelBuilder pairs a source and destination
 * property (or entity) that carry the same renamingIdentifier, so the migrator emits a RENAME
 * COLUMN / RENAME TABLE instead of dropping the old and creating a new (empty) one.
 *
 * The decisive assertion in each test is data survival under the NEW name: a drop+add would
 * leave the renamed column/table empty, so a preserved value proves the rename path ran
 * (newRenameColumnStatement / newRenameTableStatement).
 */
final class SQLStoreMigratorRenameTest extends SQLMigrationTestCase
{
    /** @noinspection PhpSameParameterValueInspection */
    private static function attribute(string $name, AttributeType $type, ?string $renamingIdentifier = null): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        if ($renamingIdentifier !== null) {
            $attribute->renamingIdentifier = $renamingIdentifier;
        }
        return $attribute;
    }

    /**
     * @param class-string<ManagedObject> $class
     * @param list<AttributeDescription> $attributes
     */
    private static function model(string $entityName, string $class, array $attributes, ?string $entityRenamingIdentifier = null): ManagedObjectModel
    {
        $entity = new EntityDescription();
        $entity->name = $entityName;
        $entity->managedObjectClassName = $class;
        if ($entityRenamingIdentifier !== null) {
            $entity->renamingIdentifier = $entityRenamingIdentifier;
        }
        $entity->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    /** @throws Exception */
    public function testRenamingAnAttributeRenamesTheColumnAndCarriesData(): void
    {
        // v1: Gizmo.color
        $context = $this->bootstrap(self::model("Gizmo", Gizmo::class, [
            self::attribute("color", AttributeType::string),
        ]));
        $gizmo = new Gizmo($context);
        $gizmo->color = "red";
        $context->save();

        $this->assertTrue($this->hasColumn("Gizmo", "color"), "precondition: v1 has the old column name");

        // v2: Gizmo.colour, tagged with renamingIdentifier "color" -> this is a rename.
        $v2 = self::model("Gizmo", Gizmo::class, [
            self::attribute("colour", AttributeType::string, renamingIdentifier: "color"),
        ]);
        $this->migrateTo($v2);

        $this->assertTrue($this->hasColumn("Gizmo", "colour"), "the column is renamed to the new name");
        $this->assertFalse($this->hasColumn("Gizmo", "color"), "the old column name is gone");

        // Read the renamed column straight from SQL: a real RENAME COLUMN carries the value,
        // whereas a drop+add would leave the new column empty.
        $this->assertSame(
            ["red"],
            $this->columnValues("Gizmo", "colour"),
            "the value moved to the renamed column (a drop+add would have lost it)",
        );
    }

    /** @throws Exception */
    public function testRenamingAnEntityRenamesTheTableAndCarriesData(): void
    {
        // v1: entity "Gizmo"
        $context = $this->bootstrap(self::model("Gizmo", Gizmo::class, [
            self::attribute("color", AttributeType::string),
        ]));
        $gizmo = new Gizmo($context);
        $gizmo->color = "blue";
        $context->save();

        $this->assertTrue($this->tableExists("Gizmo"), "precondition: v1 table is Gizmo");

        // v2: entity "Contraption" with renamingIdentifier "Gizmo" -> this is a table rename.
        $v2 = self::model(
            "Contraption",
            Contraption::class,
            [self::attribute("color", AttributeType::string)],
            entityRenamingIdentifier: "Gizmo",
        );
        $this->migrateTo($v2);

        $this->assertTrue($this->tableExists("Contraption"), "the table is renamed to the new entity name");
        $this->assertFalse($this->tableExists("Gizmo"), "the old table name is gone");

        // The row (and its data) rode along with the table rename.
        $this->assertSame(
            ["blue"],
            $this->columnValues("Contraption", "color"),
            "data moved to the renamed table (a drop+add would have lost it)",
        );
    }
}
