<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use PDO;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileProtectionType;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Set;
use const Sabatier\CoreData\InferMappingModelAutomaticallyOption;
use const Sabatier\CoreData\MigratePersistentStoresAutomaticallyOption;
use const Sabatier\CoreData\PersistentHistoryTrackingKey;
use const Sabatier\CoreData\PersistentStoreFileProtectionKey;

/**
 * @property string|null $name
 * @property Set<Course>|null $courses
 */
final class Student extends ManagedObject
{
}

/**
 * @property string|null $title
 * @property Set<Student>|null $students
 */
final class Course extends ManagedObject
{
}

final class SQLFileProtectionTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $courses = new RelationshipDescription();
        $courses->name = "courses";
        $courses->lazyDestinationEntityName = "Course";
        $courses->lazyInverseRelationshipName = "students";
        $courses->isToMany = true;

        $student = new EntityDescription();
        $student->name = "Student";
        $student->managedObjectClassName = Student::class;
        $student->properties = new ArrayClass([$name, $courses]);

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $students = new RelationshipDescription();
        $students->name = "students";
        $students->lazyDestinationEntityName = "Student";
        $students->lazyInverseRelationshipName = "courses";
        $students->isToMany = true;

        $course = new EntityDescription();
        $course->name = "Course";
        $course->managedObjectClassName = Course::class;
        $course->properties = new ArrayClass([$title, $students]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$student, $course]);
        return $model;
    }

    /** @throws Exception */
    private function open(string $protection): ManagedObjectContext
    {
        return $this->openContext(self::model(), new Dictionary([PersistentStoreFileProtectionKey => $protection, PersistentHistoryTrackingKey => true, MigratePersistentStoresAutomaticallyOption => true, InferMappingModelAutomaticallyOption => true]));
    }

    private function hasKeyManagementPlugin(): bool
    {
        return (bool)$this->pdo->query("SELECT COUNT(*) FROM information_schema.PLUGINS WHERE PLUGIN_TYPE = 'ENCRYPTION' AND PLUGIN_STATUS = 'ACTIVE'")->fetchColumn();
    }

    private function hasTable(string $name): bool
    {
        return (bool)$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '" . self::DATABASE_NAME . "' AND table_name = '$name'")->fetchColumn();
    }

    /** @return Dictionary<string> */
    private function createOptionsByTable(): Dictionary
    {
        $statement = $this->pdo->prepare("SELECT TABLE_NAME, CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?");
        $statement->execute([self::DATABASE_NAME]);
        return new Dictionary($statement->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private static function isEncrypted(string $createOptions): bool
    {
        return (bool)preg_match("/`?ENCRYPTED`?='?YES'?/i", $createOptions);
    }

    private static function isDeclaredUnencrypted(string $createOptions): bool
    {
        return (bool)preg_match("/`?ENCRYPTED`?='?NO'?/i", $createOptions);
    }

    /** @throws Exception */
    private function seed(ManagedObjectContext $context): void
    {
        $student = new Student($context);
        $student->name = "Ada";
        $course = new Course($context);
        $course->title = "Analysis";
        $student->courses = new Set([$course]);
        $context->save();
    }

    /** @throws Exception */
    private function assertSeedSurvives(ManagedObjectContext $context): void
    {
        $this->assertSame(1, $context->count(Student::fetchRequest()));
        $this->assertSame(1, $context->count(Course::fetchRequest()));
        /** @var Student $student */
        $student = $context->fetch(Student::fetchRequest())->first;
        $this->assertSame("Ada", $student->name);
        $this->assertSame(["Analysis"], $student->courses?->map(fn(Course $course): ?string => $course->title)->array);
    }

    /** @throws Exception */
    public function testWithoutTheOptionEveryTableIsDeclaredUnencrypted(): void
    {
        $this->bootstrap(self::model());
        $tables = $this->createOptionsByTable();
        $this->assertFalse($tables->isEmpty);
        $this->assertSame([], $tables->filter(fn(string $options): bool => !self::isDeclaredUnencrypted($options))->keys->array);
    }

    /** @throws Exception */
    public function testAnUnknownProtectionLevelIsRefused(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/partial/");
        $this->open("partial");
    }

    /** @throws Exception */
    public function testCompleteWithoutAKeyManagementPluginRefusesToOpen(): void
    {
        if ($this->hasKeyManagementPlugin()) {
            $this->markTestSkipped("this server has a key management plugin");
        }
        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/key management plugin/");
        $this->open(FileProtectionType::complete);
    }

    /** @throws Exception */
    public function testCompleteEncryptsEveryTable(): void
    {
        if (!$this->hasKeyManagementPlugin()) {
            $this->markTestSkipped("this server has no key management plugin");
        }
        $this->open(FileProtectionType::complete);
        $tables = $this->createOptionsByTable();
        $this->assertTrue($this->hasTable("Student"));
        $this->assertTrue($this->hasTable("ManagedObjectModel"));
        $this->assertTrue($this->hasTable("PersistentStoreMetadata"));
        $this->assertSame([], $tables->filter(fn(string $options): bool => !self::isEncrypted($options))->keys->array);
    }

    /** @throws Exception */
    public function testDataRoundTripsThroughEncryptedTables(): void
    {
        if (!$this->hasKeyManagementPlugin()) {
            $this->markTestSkipped("this server has no key management plugin");
        }
        $this->seed($this->open(FileProtectionType::complete));
        $this->assertSeedSurvives($this->open(FileProtectionType::complete));
    }

    /** @throws Exception */
    public function testCompleteEncryptsTheTablesOfAStoreCreatedWithoutIt(): void
    {
        if (!$this->hasKeyManagementPlugin()) {
            $this->markTestSkipped("this server has no key management plugin");
        }
        $this->seed($this->bootstrap(self::model()));
        $context = $this->open(FileProtectionType::complete);
        $this->assertSame([], $this->createOptionsByTable()->filter(fn(string $options): bool => !self::isEncrypted($options))->keys->array);
        $this->assertSeedSurvives($context);
    }

    /** @throws Exception */
    public function testNoneDecryptsTheTablesOfAStoreCreatedWithComplete(): void
    {
        if (!$this->hasKeyManagementPlugin()) {
            $this->markTestSkipped("this server has no key management plugin");
        }
        $this->seed($this->open(FileProtectionType::complete));
        $context = $this->open(FileProtectionType::none);
        $this->assertSame([], $this->createOptionsByTable()->filter(fn(string $options): bool => !self::isDeclaredUnencrypted($options))->keys->array);
        $this->assertSeedSurvives($context);
    }
}
