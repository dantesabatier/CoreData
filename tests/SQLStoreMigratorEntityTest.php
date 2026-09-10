<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;

/**
 * @property string $name
 */
final class Scribe extends ManagedObject
{
}

/**
 * @property string $title
 */
final class Tome extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over the entity mapping family:
 * addEntityMappingType, removeEntityMappingType and copyEntityMappingType (as opposed to
 * the attribute-level transform family). These exercise processAddedEntityMappings,
 * processRemovedEntityMappings and processCopiedEntityMappings.
 *
 * The mapping type is inferred by MappingModelBuilder from renamingIdentifier (entity
 * presence across models) and versionHash (whether an entity's shape changed), so the v1/v2
 * model pairs here are shaped to provoke each specific type.
 */
final class SQLStoreMigratorEntityTest extends SQLMigrationTestCase
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
    private static function entity(string $name, string $class, array $attributes): EntityDescription
    {
        $entity = new EntityDescription();
        $entity->name = $name;
        $entity->managedObjectClassName = $class;
        $entity->properties = new ArrayClass($attributes);
        return $entity;
    }

    private static function model(EntityDescription ...$entities): ManagedObjectModel
    {
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass($entities);
        return $model;
    }

    /** v1 with a single Scribe entity. */
    private static function authorEntity(): EntityDescription
    {
        return self::entity("Scribe", Scribe::class, [self::attribute("name", AttributeType::string)]);
    }

    public function testAddingANewEntityCreatesItsTable(): void
    {
        $this->bootstrap(self::model(self::authorEntity()));

        $this->assertTrue($this->tableExists("Scribe"), "precondition: Scribe exists in v1");
        $this->assertFalse($this->tableExists("Tome"), "precondition: Tome does not exist in v1");

        // v2 adds a brand-new Tome entity alongside the unchanged Scribe.
        $this->migrateTo(self::model(
            self::authorEntity(),
            self::entity("Tome", Tome::class, [self::attribute("title", AttributeType::string)]),
        ));

        $this->assertTrue($this->tableExists("Tome"), "the added entity gets a new table");
        $this->assertTrue($this->hasColumn("Tome", "title"), "the new table has the entity's columns");
        $this->assertTrue($this->tableExists("Scribe"), "the pre-existing entity's table is untouched");
    }

    public function testRemovingAnEntityDropsItsTable(): void
    {
        // v1 has Scribe and Tome; v2 keeps only Scribe.
        $context = $this->bootstrap(self::model(
            self::authorEntity(),
            self::entity("Tome", Tome::class, [self::attribute("title", AttributeType::string)]),
        ));
        $author = new Scribe($context);
        $author->name = "Le Guin";
        $context->save();

        $this->assertTrue($this->tableExists("Tome"), "precondition: Tome exists in v1");

        $migrated = $this->migrateTo(self::model(self::authorEntity()));

        $this->assertFalse($this->tableExists("Tome"), "the removed entity's table is dropped");
        $this->assertTrue($this->tableExists("Scribe"), "the surviving entity's table remains");

        $rows = $migrated->fetch(Scribe::fetchRequest());
        $this->assertCount(1, $rows, "data in the surviving entity is preserved");
        $this->assertSame("Le Guin", (string)$rows->first()->name, "the surviving entity's values are intact");
    }

    public function testUnchangedEntityIsCopiedWhileAnotherEntityTransforms(): void
    {
        // Scribe is identical across versions (copy), Tome gains a column (transform). The
        // copy path must leave Scribe's table and data intact.
        $context = $this->bootstrap(self::model(
            self::authorEntity(),
            self::entity("Tome", Tome::class, [self::attribute("title", AttributeType::string)]),
        ));
        $author = new Scribe($context);
        $author->name = "Borges";
        $context->save();

        $migrated = $this->migrateTo(self::model(
            self::authorEntity(),
            self::entity("Tome", Tome::class, [
                self::attribute("title", AttributeType::string),
                self::attribute("year", AttributeType::integer32),
            ]),
        ));

        $this->assertTrue($this->tableExists("Scribe"), "the copied entity's table survives");
        $this->assertSame(
            ["objectID", "version", "entityName", "name"],
            $this->columnNames("Scribe"),
            "the copied entity's schema is unchanged",
        );
        $this->assertTrue($this->hasColumn("Tome", "year"), "the transformed entity gains its new column");

        $rows = $migrated->fetch(Scribe::fetchRequest());
        $this->assertCount(1, $rows, "the copied entity's data is preserved");
        $this->assertSame("Borges", (string)$rows->first()->name, "the copied entity's values are intact");
    }
}
