<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;

/**
 * @property Citizen $holder
 */
final class Passport extends ManagedObject
{
}

/**
 * @property string|null $name
 * @property string|null $number
 */
final class Citizen extends ManagedObject
{
}

/**
 * Suspected bug: removing an entity that is the target of a ONE-TO-ONE relationship (to-one on
 * both sides) from a surviving entity. The surviving entity keeps a foreign-key constraint into
 * the removed entity's table; if that inbound FK is not dropped first, DROP TABLE fails on
 * MariaDB (errno 190).
 *
 * v1: Citizen -passport-> Passport (to-one), inverse Passport -holder-> Citizen (to-one).
 * v2: the Passport entity is removed; Citizen survives.
 */
final class SQLStoreMigratorRemoveOneToOneTest extends SQLMigrationTestCase
{
    private static function attribute(string $name): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = AttributeType::string;
        $attribute->isOptional = true;
        return $attribute;
    }

    private static function model(bool $withPassport): ManagedObjectModel
    {
        $entities = [];

        $citizenProps = [self::attribute("name")];
        if ($withPassport) {
            $passportRel = new RelationshipDescription();
            $passportRel->name = "passport";
            $passportRel->lazyDestinationEntityName = "Passport";
            $passportRel->lazyInverseRelationshipName = "holder";
            $passportRel->maxCount = 1;
            $citizenProps[] = $passportRel;

            $holder = new RelationshipDescription();
            $holder->name = "holder";
            $holder->lazyDestinationEntityName = "Citizen";
            $holder->lazyInverseRelationshipName = "passport";
            $holder->maxCount = 1; // one-to-one: both sides to-one

            $passport = new EntityDescription();
            $passport->name = "Passport";
            $passport->managedObjectClassName = Passport::class;
            $passport->properties = new ArrayClass([self::attribute("number"), $holder]);
            $entities[] = $passport;
        }

        $citizen = new EntityDescription();
        $citizen->name = "Citizen";
        $citizen->managedObjectClassName = Citizen::class;
        $citizen->properties = new ArrayClass($citizenProps);
        $entities[] = $citizen;

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass($entities);
        return $model;
    }

    /** @throws Exception */
    public function testRemovingAOneToOneTargetEntityDropsItsTable(): void
    {
        $context = $this->bootstrap(self::model(withPassport: true));
        $citizen = new Citizen($context);
        $citizen->name = "Ada";
        $context->save();

        $this->assertTrue($this->tableExists("Passport"), "precondition: Passport exists in v1");

        // Remove the Passport entity. If the inbound FK from Citizen is not dropped first, this
        // migration crashes on DROP TABLE.
        $migrated = $this->migrateTo(self::model(withPassport: false));

        $this->assertFalse($this->tableExists("Passport"), "the removed one-to-one target table is dropped");
        $this->assertTrue($this->tableExists("Citizen"), "the surviving entity remains");

        $rows = $migrated->fetch(Citizen::fetchRequest());
        $this->assertCount(1, $rows, "the surviving entity's data is intact");
    }
}
