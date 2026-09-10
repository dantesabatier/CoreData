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
use Sabatier\Foundation\Set;

/**
 * @property Set<Filial> $child
 * @method void addChildObject(Filial $object)
 * @method void removeChildObject(Filial $object)
 * @method void addChild(Set<Filial> $objects)
 * @method void removeChild(Set<Filial> $objects)
 * @method Set<Filial> intersectChild(Set<Filial> $objects)
 * @method void setChild(Set<Filial> $objects)
 */
final class Parintore extends ManagedObject
{
}

/**
 * @property Parintore $parent
 */
final class Filial extends ManagedObject
{
}

/**
 * Characterization tests for the to-one <-> to-many transition whose INVERSE stays to-one, so
 * it maps to SQLToMany (a foreign key that relocates to the destination side) rather than to a
 * many-to-many pivot table.
 *
 * Model: Parintore -child-> Filial, inverse Filial -parent-> Parintore (always to-one).
 *  - v1: "child" is to-one  -> Parintore holds a childID FK; Filial also holds a parentID FK.
 *  - v2: "child" is to-many -> the relationship is carried solely by Filial.parentID; the old
 *    Parintore.childID column is obsolete and must be dropped.
 */
final class SQLStoreMigratorToManyTransitionTest extends SQLMigrationTestCase
{
    private static function attribute(string $name): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = AttributeType::string;
        return $attribute;
    }

    private static function model(bool $childToMany): ManagedObjectModel
    {
        $child = new RelationshipDescription();
        $child->name = "child";
        $child->lazyDestinationEntityName = "Filial";
        $child->lazyInverseRelationshipName = "parent";
        if ($childToMany) {
            $child->isToMany = true;
        } else {
            $child->maxCount = 1;
        }

        $parent = new RelationshipDescription();
        $parent->name = "parent";
        $parent->lazyDestinationEntityName = "Parintore";
        $parent->lazyInverseRelationshipName = "child";
        $parent->maxCount = 1; // inverse stays to-one

        $parintore = new EntityDescription();
        $parintore->name = "Parintore";
        $parintore->managedObjectClassName = Parintore::class;
        $parintore->properties = new ArrayClass([self::attribute("title"), $child]);

        $filial = new EntityDescription();
        $filial->name = "Filial";
        $filial->managedObjectClassName = Filial::class;
        $filial->properties = new ArrayClass([self::attribute("name"), $parent]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$parintore, $filial]);
        return $model;
    }

    public function testToOneToManyWithToOneInverseDropsTheObsoleteForeignKey(): void
    {
        // v1: child is to-one -> Parintore has a childID FK column.
        $context = $this->bootstrap(self::model(childToMany: false));
        $parintore = new Parintore($context);
        $parintore->title = "root";
        $context->save();

        $this->assertTrue($this->hasColumn("Parintore", "childID"), "precondition: to-one child FK on Parintore");
        $this->assertTrue($this->hasColumn("Filial", "parentID"), "precondition: to-one parent FK on Filial");

        // v2: child becomes to-many (inverse parent stays to-one) -> the relationship is carried
        // by Filial.parentID; Parintore.childID is obsolete.
        $this->migrateTo(self::model(childToMany: true));

        $this->assertFalse(
            $this->hasColumn("Parintore", "childID"),
            "the obsolete childID FK on the parent side is dropped",
        );
        $this->assertTrue(
            $this->hasColumn("Filial", "parentID"),
            "the surviving parentID FK (which now carries the to-many) remains",
        );

        $this->assertSame(
            ["root"],
            $this->columnValues("Parintore", "title"),
            "the entity's own data survives the SQLToMany transition",
        );
    }
}
