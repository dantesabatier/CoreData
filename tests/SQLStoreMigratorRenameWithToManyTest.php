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

/**
 * @property string $code
 */
final class Depot extends ManagedObject
{
}

/**
 * @property string $code
 */
final class Storehouse extends ManagedObject
{
}

/**
 * @property string $label
 */
final class Pallet extends ManagedObject
{
}

/**
 * Characterization tests for renaming an entity that a to-many relationship points at.
 *
 * A plain table rename is already covered by SQLStoreMigratorRenameTest. This is the harder
 * case: the renamed entity owns a to-many, so its inverse to-one's foreign key lives on the
 * OTHER table and its column name is derived from the renamed entity. The migrator therefore
 * cannot rename the table alone — it must drop the foreign key's index, rename the column
 * underneath it, and create the index again, in that order. Renaming the column with the
 * constraint still in place is what MariaDB rejects.
 *
 * Model: Depot -1----*- Pallet, so the FK column "depotID" lands on the Pallet table and
 * becomes "storehouseID" once Depot is renamed to Storehouse.
 *
 * What pins this is the surviving link, not the constraint: a later pass recreates the foreign
 * key index for every relationship it materializes, so suppressing the rename path's own
 * creation still leaves FK_Pallet_Storehouse in place. Suppressing the rename path entirely,
 * however, empties the column — measured. The column value is therefore the only assertion here
 * that fails when the maintenance stops running.
 */
final class SQLStoreMigratorRenameWithToManyTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        return $attribute;
    }

    /**
     * The owner entity under $name, holding a to-many "pallets" whose inverse to-one names the
     * owner by its lowercased name — which is what makes the foreign-key column follow the
     * rename. $renamingIdentifier tags the entity as a rename of an earlier one.
     *
     * @param string $name
     * @param class-string<ManagedObject> $class
     * @param string $inverseName
     * @param string|null $renamingIdentifier
     */
    private static function model(string $name, string $class, string $inverseName, ?string $renamingIdentifier = null): ManagedObjectModel
    {
        $pallets = new RelationshipDescription();
        $pallets->name = "pallets";
        $pallets->lazyDestinationEntityName = "Pallet";
        $pallets->lazyInverseRelationshipName = $inverseName;
        $pallets->isToMany = true;

        $owner = new EntityDescription();
        $owner->name = $name;
        $owner->managedObjectClassName = $class;
        if ($renamingIdentifier !== null) {
            $owner->renamingIdentifier = $renamingIdentifier;
        }
        $owner->properties = new ArrayClass([self::attribute("code", AttributeType::string), $pallets]);

        $inverse = new RelationshipDescription();
        $inverse->name = $inverseName;
        $inverse->lazyDestinationEntityName = $name;
        $inverse->lazyInverseRelationshipName = "pallets";
        $inverse->maxCount = 1;

        $pallet = new EntityDescription();
        $pallet->name = "Pallet";
        $pallet->managedObjectClassName = Pallet::class;
        $pallet->properties = new ArrayClass([self::attribute("label", AttributeType::string), $inverse]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$owner, $pallet]);
        return $model;
    }

    /**
     * Renaming the owner renames its table, and carries the foreign key on the other table with
     * it: the old column and its constraint are gone, the new ones exist, and the link survives.
     */
    public function testRenamingTheOwnerRenamesTheForeignKeyColumnOnTheOtherTable(): void
    {
        $context = $this->bootstrap(self::model("Depot", Depot::class, "depot"));
        $depot = new Depot($context);
        $depot->code = "D1";
        $pallet = new Pallet($context);
        $pallet->label = "P1";
        $pallet->depot = $depot;
        $context->save();

        $this->assertTrue($this->hasColumn("Pallet", "depotID"), "precondition: v1 names the FK column after the old entity");
        $this->assertContains("FK_Pallet_Depot", $this->foreignKeyNames("Pallet"), "precondition: v1 has the FK constraint");

        $this->migrateTo(self::model("Storehouse", Storehouse::class, "storehouse", renamingIdentifier: "Depot"));

        $this->assertTrue($this->tableExists("Storehouse"), "the owner table is renamed");
        $this->assertFalse($this->tableExists("Depot"), "the old owner table is gone");
        $this->assertTrue($this->hasColumn("Pallet", "storehouseID"), "the FK column on the other table follows the rename");
        $this->assertFalse($this->hasColumn("Pallet", "depotID"), "the old FK column is gone");
        $this->assertNotContains("FK_Pallet_Depot", $this->foreignKeyNames("Pallet"), "the old constraint is dropped");
        $this->assertContains("FK_Pallet_Storehouse", $this->foreignKeyNames("Pallet"), "the destination table ends up with a constraint under the new name");
    }

    /**
     * And the rename moves the data rather than recreating an empty structure: the row on each
     * side survives and the pallet still points at the same owner row.
     */
    public function testTheRelationshipSurvivesTheRename(): void
    {
        $context = $this->bootstrap(self::model("Depot", Depot::class, "depot"));
        $depot = new Depot($context);
        $depot->code = "D1";
        $pallet = new Pallet($context);
        $pallet->label = "P1";
        $pallet->depot = $depot;
        $context->save();

        $ownerReference = $this->columnValues("Depot", "objectID");

        $this->migrateTo(self::model("Storehouse", Storehouse::class, "storehouse", renamingIdentifier: "Depot"));

        $this->assertSame(["D1"], $this->columnValues("Storehouse", "code"), "the owner row rode along with the table rename");
        $this->assertSame($ownerReference, $this->columnValues("Storehouse", "objectID"), "the owner keeps its identity, so foreign keys still resolve");
        $this->assertSame($ownerReference, $this->columnValues("Pallet", "storehouseID"), "the renamed column still holds the link (a drop+add would be empty)");
    }
}
