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
 * @property string $topic
 */
final class Learner extends ManagedObject
{
}

final class Seminar extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over the many-to-many relationship family. When
 * both sides of a relationship are to-many, the framework maps it to SQLManyToMany, backed by a
 * correlation (pivot) table rather than a foreign-key column. The pivot table name is the two
 * entity table names concatenated in descending tableName order (so "Seminar"+"Learner" ->
 * "SeminarLearner").
 *
 * Covered:
 *  - adding a many-to-many relationship creates the pivot table with its two foreign keys
 *    (processTransformedEntityMappings' SQLManyToMany branch -> newCreateTableStatementForManyToMany);
 *  - removing a many-to-many relationship drops the pivot table (removedManyToMany path).
 */
final class SQLStoreMigratorManyToManyRelationshipTest extends SQLMigrationTestCase
{
    /** Descending tableName order: "Seminar" (S) precedes "Learner" (L). */
    private const string PIVOT_TABLE = "SeminarLearner";

    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        return $attribute;
    }

    /**
     * A Learner <-> Seminar model. When $related is true, both entities carry a to-many
     * relationship whose inverse is also to-many, producing a many-to-many (pivot) mapping.
     */
    private static function model(bool $related): ManagedObjectModel
    {
        $learnerProps = [self::attribute("name", AttributeType::string)];
        $seminarProps = [self::attribute("topic", AttributeType::string)];

        if ($related) {
            $seminars = new RelationshipDescription();
            $seminars->name = "seminars";
            $seminars->lazyDestinationEntityName = "Seminar";
            $seminars->lazyInverseRelationshipName = "learners";
            $seminars->isToMany = true;
            $learnerProps[] = $seminars;

            $learners = new RelationshipDescription();
            $learners->name = "learners";
            $learners->lazyDestinationEntityName = "Learner";
            $learners->lazyInverseRelationshipName = "seminars";
            $learners->isToMany = true;
            $seminarProps[] = $learners;
        }

        $learner = new EntityDescription();
        $learner->name = "Learner";
        $learner->managedObjectClassName = Learner::class;
        $learner->properties = new ArrayClass($learnerProps);

        $seminar = new EntityDescription();
        $seminar->name = "Seminar";
        $seminar->managedObjectClassName = Seminar::class;
        $seminar->properties = new ArrayClass($seminarProps);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$learner, $seminar]);
        return $model;
    }

    public function testAddingAManyToManyRelationshipCreatesThePivotTable(): void
    {
        $context = $this->bootstrap(self::model(related: false));
        $learner = new Learner($context);
        $learner->name = "Ada";
        $context->save();

        $this->assertFalse($this->tableExists(self::PIVOT_TABLE), "precondition: no pivot table in v1");

        $migrated = $this->migrateTo(self::model(related: true));

        $this->assertTrue(
            $this->tableExists(self::PIVOT_TABLE),
            "adding a many-to-many relationship creates the correlation (pivot) table",
        );
        $this->assertCount(
            2,
            $this->foreignKeyNames(self::PIVOT_TABLE),
            "the pivot table has a foreign key to each side of the relationship",
        );

        $rows = $migrated->fetch(Learner::fetchRequest());
        $this->assertCount(1, $rows, "the existing row survives adding a many-to-many relationship");
        $this->assertSame("Ada", (string)$rows->first()->name, "the pre-existing value is preserved");
    }

    public function testRemovingAManyToManyRelationshipDropsThePivotTable(): void
    {
        $this->bootstrap(self::model(related: true));

        $this->assertTrue($this->tableExists(self::PIVOT_TABLE), "precondition: pivot table exists in v1");

        $this->migrateTo(self::model(related: false));

        $this->assertFalse(
            $this->tableExists(self::PIVOT_TABLE),
            "removing a many-to-many relationship drops the correlation (pivot) table",
        );
        $this->assertTrue($this->tableExists("Learner"), "the entity tables themselves remain");
        $this->assertTrue($this->tableExists("Seminar"), "the entity tables themselves remain");
    }
}
