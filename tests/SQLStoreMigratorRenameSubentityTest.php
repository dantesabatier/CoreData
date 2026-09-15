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
 * @property string $name
 */
abstract class VehicleBase extends ManagedObject
{
}

/**
 * @property string $plate
 */
final class Lorry extends VehicleBase
{
}

/**
 * @property string $plate
 */
final class Truck extends VehicleBase
{
}

/**
 * @property string $reference
 */
final class Consignment extends ManagedObject
{
}

/**
 * Characterization tests for renaming a SUBENTITY that owns a to-many relationship.
 *
 * This is the inheritance counterpart of SQLStoreMigratorRenameWithToManyTest. It exercises a
 * different path in the migrator: renaming a whole entity renames its table, but a subentity
 * shares its superentity's table, so there is no table to rename. What still has to move is the
 * foreign key belonging to the subentity's own to-many, whose column is named after the
 * subentity and therefore follows the rename — on the destination table, where the inverse
 * to-one lives.
 *
 * Model: an abstract Vehicle with one subentity (Lorry, renamed to Truck in v2), and the
 * subentity owns a to-many "consignments". The FK column "lorryID" on the Consignment table
 * becomes "truckID".
 *
 * Tagging the subentity with a renamingIdentifier is NOT enough to make the migration happen: an
 * entity's version hash does not take its subentities into account, so the superentity's hash is
 * unchanged by the rename and its mapping is classified as a copy rather than a transformation —
 * and this path only ever visits transformations. The superentity therefore carries a
 * versionHashModifier, which is what tells Core Data its hierarchy is a different version.
 */
final class SQLStoreMigratorRenameSubentityTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        return $attribute;
    }

    /**
     * An abstract Vehicle whose single subentity is named $name and owns a to-many
     * "consignments" whose inverse to-one is $inverseName — the name the foreign-key column is
     * derived from. $renamingIdentifier tags the subentity as a rename of an earlier one.
     *
     * @param string $name
     * @param class-string<ManagedObject> $class
     * @param string $inverseName
     * @param string|null $renamingIdentifier
     */
    private static function model(string $name, string $class, string $inverseName, ?string $renamingIdentifier = null): ManagedObjectModel
    {
        $vehicle = new EntityDescription();
        $vehicle->name = "Vehicle";
        $vehicle->managedObjectClassName = VehicleBase::class;
        $vehicle->isAbstract = true;
        if ($renamingIdentifier !== null) {
            $vehicle->versionHashModifier = $name;
        }
        $vehicle->properties = new ArrayClass([self::attribute("name", AttributeType::string)]);

        $consignments = new RelationshipDescription();
        $consignments->name = "consignments";
        $consignments->lazyDestinationEntityName = "Consignment";
        $consignments->lazyInverseRelationshipName = $inverseName;
        $consignments->isToMany = true;

        $subentity = new EntityDescription();
        $subentity->name = $name;
        $subentity->managedObjectClassName = $class;
        $subentity->superentity = $vehicle;
        if ($renamingIdentifier !== null) {
            $subentity->renamingIdentifier = $renamingIdentifier;
        }
        $subentity->properties = new ArrayClass([self::attribute("plate", AttributeType::string), $consignments]);

        $vehicle->subentities = new ArrayClass([$subentity]);

        $inverse = new RelationshipDescription();
        $inverse->name = $inverseName;
        $inverse->lazyDestinationEntityName = $name;
        $inverse->lazyInverseRelationshipName = "consignments";
        $inverse->maxCount = 1;

        $consignment = new EntityDescription();
        $consignment->name = "Consignment";
        $consignment->managedObjectClassName = Consignment::class;
        $consignment->properties = new ArrayClass([self::attribute("reference", AttributeType::string), $inverse]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$vehicle, $consignment]);
        return $model;
    }

    /**
     * Renaming the subentity moves its to-many's foreign key column on the other table, and the
     * link survives: a drop+add would leave the new column empty.
     */
    public function testRenamingASubentityCarriesItsToManyForeignKey(): void
    {
        $context = $this->bootstrap(self::model("Lorry", Lorry::class, "lorry"));
        $lorry = new Lorry($context);
        $lorry->name = "heavy";
        $lorry->plate = "AAA-111";
        $consignment = new Consignment($context);
        $consignment->reference = "C1";
        $consignment->lorry = $lorry;
        $context->save();

        $this->assertTrue($this->hasColumn("Consignment", "lorryID"), "precondition: v1 names the FK column after the subentity");
        $reference = $this->columnValues("Consignment", "lorryID");
        $this->assertSame(["1"], $reference, "precondition: the link is stored");

        $this->migrateTo(self::model("Truck", Truck::class, "truck", renamingIdentifier: "Lorry"));

        $this->assertTrue($this->hasColumn("Consignment", "truckID"), "the FK column follows the subentity rename");
        $this->assertFalse($this->hasColumn("Consignment", "lorryID"), "the old FK column is gone");
        $this->assertSame($reference, $this->columnValues("Consignment", "truckID"), "the renamed column still holds the link");
    }

    /**
     * The subentity shares the superentity's table, so the rename must not create or rename one:
     * the row stays where it was, under the inherited attribute.
     */
    public function testTheSubentityKeepsSharingTheSuperentityTable(): void
    {
        $context = $this->bootstrap(self::model("Lorry", Lorry::class, "lorry"));
        $lorry = new Lorry($context);
        $lorry->name = "heavy";
        $lorry->plate = "AAA-111";
        $context->save();

        $this->assertTrue($this->tableExists("Vehicle"), "precondition: the subentity lives in the superentity's table");
        $this->assertFalse($this->tableExists("Lorry"), "precondition: a subentity has no table of its own");

        $this->migrateTo(self::model("Truck", Truck::class, "truck", renamingIdentifier: "Lorry"));

        $this->assertTrue($this->tableExists("Vehicle"), "the shared table survives the subentity rename");
        $this->assertFalse($this->tableExists("Truck"), "the renamed subentity still has no table of its own");
        $this->assertSame(["heavy"], $this->columnValues("Vehicle", "name"), "the row survives in the shared table");
    }
}
