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

final class Item extends ManagedObject
{
}

final class Holder extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over relationship-kind transitions: a
 * relationship whose cardinality changes across model versions (to-one <-> to-many), which
 * structurally moves the data between a foreign-key column and a many-to-many pivot table.
 *
 * These transitions are handled by the SQLRelationship branch of processTransformedEntityMappings
 * (lines ~286-307), but that code only runs for transformEntityMappingType. The mapping type is
 * inferred from the entity versionHash, so a prerequisite (asserted directly below) is that the
 * entity versionHash reacts to a relationship's cardinality change — otherwise the change is
 * (wrongly) inferred as a copy and the transition code never runs.
 */
final class SQLStoreMigratorRelationshipTransitionTest extends SQLMigrationTestCase
{
    private static function attribute(string $name): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = AttributeType::string;
        return $attribute;
    }

    /**
     * Item -owner-> Holder, with inverse Holder -items-> Item (always to-many). When
     * $ownerToMany is false, "owner" is to-one (FK column ownerID on Item); when true, "owner"
     * is to-many, making both sides to-many => a many-to-many pivot table.
     */
    private static function model(bool $ownerToMany): ManagedObjectModel
    {
        $owner = new RelationshipDescription();
        $owner->name = "owner";
        $owner->lazyDestinationEntityName = "Holder";
        $owner->lazyInverseRelationshipName = "items";
        if ($ownerToMany) {
            $owner->isToMany = true;
        } else {
            $owner->maxCount = 1;
        }

        $items = new RelationshipDescription();
        $items->name = "items";
        $items->lazyDestinationEntityName = "Item";
        $items->lazyInverseRelationshipName = "owner";
        $items->isToMany = true;

        $item = new EntityDescription();
        $item->name = "Item";
        $item->managedObjectClassName = Item::class;
        $item->properties = new ArrayClass([self::attribute("label"), $owner]);

        $holder = new EntityDescription();
        $holder->name = "Holder";
        $holder->managedObjectClassName = Holder::class;
        $holder->properties = new ArrayClass([self::attribute("name"), $items]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$item, $holder]);
        return $model;
    }

    /**
     * Root cause guard: the entity's versionHash must differ between a to-one and a to-many
     * "owner" relationship. If it does not, the migration is inferred as a copy and the
     * schema change is silently skipped.
     */
    public function testEntityVersionHashReactsToRelationshipCardinalityChange(): void
    {
        $toOne = self::model(ownerToMany: false)->entitiesByName["Item"];
        $toMany = self::model(ownerToMany: true)->entitiesByName["Item"];

        $this->assertNotSame(
            $toOne->versionHash,
            $toMany->versionHash,
            "changing a relationship from to-one to to-many must change the entity version hash",
        );
    }

    public function testToOneBecomesManyToManyCreatesPivotTable(): void
    {
        // v1: owner is to-one -> Item has an ownerID FK column, no pivot table.
        $context = $this->bootstrap(self::model(ownerToMany: false));
        $item = new Item($context);
        $item->label = "widget";
        $context->save();

        $this->assertTrue($this->hasColumn("Item", "ownerID"), "precondition: to-one FK column exists in v1");

        // v2: owner is to-many -> both sides to-many -> a many-to-many pivot table.
        // Pivot name: entity table names concatenated in descending order ("Item" > "Holder").
        $this->migrateTo(self::model(ownerToMany: true));

        $this->assertTrue(
            $this->tableExists("ItemHolder"),
            "to-one -> to-many (many-to-many) creates the correlation pivot table",
        );

        // The Item row itself survives the structural change (the relationship is re-homed,
        // but the entity's own data must remain).
        $this->assertSame(
            ["widget"],
            $this->columnValues("Item", "label"),
            "the entity's own data survives the relationship transition",
        );
    }

    /**
     * When a to-one relationship becomes many-to-many, the obsolete to-one foreign-key column
     * must be dropped: the relationship now lives in a pivot table, so the old FK column would
     * otherwise linger as dead schema. In processTransformedEntityMappings the source appears
     * both as SQLToOne (which creates the pivot) and as SQLForeignKey; the SQLForeignKey source,
     * matched to a non-foreign-key destination, is routed to removedColumns.
     */
    public function testToOneBecomesManyToManyDropsObsoleteForeignKeyColumn(): void
    {
        $this->bootstrap(self::model(ownerToMany: false));

        $this->assertTrue($this->hasColumn("Item", "ownerID"), "precondition: to-one FK column exists in v1");

        $this->migrateTo(self::model(ownerToMany: true));

        $this->assertFalse(
            $this->hasColumn("Item", "ownerID"),
            "the obsolete to-one FK column is dropped once the relationship becomes many-to-many",
        );
    }
}
