<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SQLManyToMany;
use Sabatier\CoreData\SQLModel;
use Sabatier\Foundation\ArrayClass;

/**
 * Tests for src/SQLManyToMany.php — specifically the correlation (join) table naming.
 *
 * Regression guard:
 *  - correlationTableName concatenates the two joined entities' table names in a fixed
 *    order, and SQLManyToMany::isEqual compares relationships by that name, so the join
 *    table's identity must be stable and independent of which side of the relationship
 *    computes it. The order comes from sorting the entities by tableName; when the
 *    Foundation SortDescriptor direction bug was fixed, this code had to switch to an
 *    explicit descending descriptor to keep producing the original order. If that order
 *    ever flips, the correlation table name (a real database table) changes and the two
 *    sides of the relationship stop agreeing — exactly what these tests catch.
 *
 * SQLManyToMany is internal and deeply coupled; it is exercised the way the framework
 * builds it — through an SQLModel over a ManagedObjectModel with a many-to-many
 * relationship — rather than constructed directly. No database connection is needed to
 * read the derived naming.
 */
final class SQLManyToManyTest extends TestCase
{
    private SQLModel $sqlModel;

    /** A Student <-> Course model where both sides of the relationship are to-many. */
    private static function makeManyToManyModel(): ManagedObjectModel
    {
        $studentName = new AttributeDescription();
        $studentName->name = "name";
        $studentName->type = AttributeType::string;

        $courses = new RelationshipDescription();
        $courses->name = "courses";
        $courses->lazyDestinationEntityName = "Course";
        $courses->lazyInverseRelationshipName = "students";
        $courses->isToMany = true;

        $student = new EntityDescription();
        $student->name = "Student";
        $student->properties = new ArrayClass([$studentName, $courses]);

        $courseName = new AttributeDescription();
        $courseName->name = "title";
        $courseName->type = AttributeType::string;

        $students = new RelationshipDescription();
        $students->name = "students";
        $students->lazyDestinationEntityName = "Student";
        $students->lazyInverseRelationshipName = "courses";
        $students->isToMany = true;

        $course = new EntityDescription();
        $course->name = "Course";
        $course->properties = new ArrayClass([$courseName, $students]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$student, $course]);
        return $model;
    }

    private function manyToMany(string $entityName, string $relationshipName): SQLManyToMany
    {
        $relationship = $this->sqlModel->entitiesByName[$entityName]->propertiesByName[$relationshipName];
        $this->assertInstanceOf(SQLManyToMany::class, $relationship, "a to-many relationship whose inverse is also to-many maps to SQLManyToMany");
        return $relationship;
    }

    protected function setUp(): void
    {
        $this->sqlModel = new SQLModel(self::makeManyToManyModel(), "default");
    }

    public function testCorrelationTableNameJoinsTheEntityTableNames(): void
    {
        // Sorted descending by tableName: "Student" (S) precedes "Course" (C).
        $this->assertSame("StudentCourse", $this->manyToMany("Student", "courses")->correlationTableName);
    }

    public function testCorrelationTableNameIsIdenticalFromBothSides(): void
    {
        $studentSide = $this->manyToMany("Student", "courses");
        $courseSide = $this->manyToMany("Course", "students");

        $this->assertSame($studentSide->correlationTableName, $courseSide->correlationTableName, "both sides of the relationship must derive the same join table name");
    }

    public function testEqualityHoldsAcrossBothSidesOfTheRelationship(): void
    {
        $studentSide = $this->manyToMany("Student", "courses");
        $courseSide = $this->manyToMany("Course", "students");

        // isEqual compares by correlationTableName, so a stable, side-independent name is
        // what makes the two directions of one relationship compare equal.
        $this->assertTrue($studentSide->isEqual($courseSide), "the two sides describe the same join and must be equal");
    }
}
