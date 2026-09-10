<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DeleteRule;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;

/**
 * @property string $name
 * @property string $title
 */
final class Manuscript extends ManagedObject
{
}

/**
 * @property string $name
 * @property string $title
 */
final class Publisher extends ManagedObject
{
}

/**
 * Characterization tests for SQLStoreMigrator over the to-one relationship family. A to-one
 * relationship is backed by a foreign-key column "<name>ID" on the owning entity's table plus
 * a foreign-key constraint named "FK_<table>_<Ucfirst(name)>".
 *
 * Covered here:
 *  - adding a to-one relationship creates the FK column and its constraint
 *    (processTransformedEntityMappings' persistentProperties/SQLToOne branch);
 *  - the delete rule is reflected in the constraint's ON DELETE action.
 *
 * The inverse (Publisher -> manuscripts) is to-many, so the FK lives on the Manuscript side.
 * Crucially, the FK's ON DELETE action is derived from the INVERSE (to-many) relationship's
 * delete rule — "what happens to the Manuscripts when their Publisher is deleted" is governed
 * by the Publisher->manuscripts rule, not by the Manuscript->publisher rule. So the delete
 * rule under test is set on the "manuscripts" side.
 */
final class SQLStoreMigratorToOneRelationshipTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        return $attribute;
    }

    /**
     * Builds the two-entity model. When $withPublisherRelationship is true, Manuscript gains
     * a to-one "publisher" relationship (inverse to-many "manuscripts" on Publisher). The FK's
     * ON DELETE action comes from the inverse (to-many) side, so $inverseDeleteRule is applied
     * to "manuscripts".
     */
    private static function model(bool $withPublisherRelationship, DeleteRule $inverseDeleteRule = DeleteRule::nullifyDeleteRule): ManagedObjectModel
    {
        $manuscriptProps = [self::attribute("title", AttributeType::string)];
        $publisherProps = [self::attribute("name", AttributeType::string)];

        if ($withPublisherRelationship) {
            $publisher = new RelationshipDescription();
            $publisher->name = "publisher";
            $publisher->lazyDestinationEntityName = "Publisher";
            $publisher->lazyInverseRelationshipName = "manuscripts";
            $publisher->maxCount = 1;
            $manuscriptProps[] = $publisher;

            $manuscripts = new RelationshipDescription();
            $manuscripts->name = "manuscripts";
            $manuscripts->lazyDestinationEntityName = "Manuscript";
            $manuscripts->lazyInverseRelationshipName = "publisher";
            $manuscripts->isToMany = true;
            $manuscripts->deleteRule = $inverseDeleteRule;
            $publisherProps[] = $manuscripts;
        }

        $manuscript = new EntityDescription();
        $manuscript->name = "Manuscript";
        $manuscript->managedObjectClassName = Manuscript::class;
        $manuscript->properties = new ArrayClass($manuscriptProps);

        $publisher = new EntityDescription();
        $publisher->name = "Publisher";
        $publisher->managedObjectClassName = Publisher::class;
        $publisher->properties = new ArrayClass($publisherProps);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$manuscript, $publisher]);
        return $model;
    }

    public function testAddingAToOneRelationshipCreatesTheForeignKeyColumn(): void
    {
        $context = $this->bootstrap(self::model(withPublisherRelationship: false));
        $manuscript = new Manuscript($context);
        $manuscript->title = "Draft";
        $context->save();

        $this->assertFalse($this->hasColumn("Manuscript", "publisherID"), "precondition: no FK column in v1");

        $migrated = $this->migrateTo(self::model(withPublisherRelationship: true));

        $this->assertTrue(
            $this->hasColumn("Manuscript", "publisherID"),
            "adding a to-one relationship creates the <name>ID foreign-key column",
        );

        $rows = $migrated->fetch(Manuscript::fetchRequest());
        $this->assertCount(1, $rows, "the existing row survives adding a relationship");
        $this->assertSame("Draft", (string)$rows->first()->title, "the pre-existing value is preserved");
    }

    public function testAddingAToOneRelationshipCreatesTheForeignKeyConstraint(): void
    {
        $this->bootstrap(self::model(withPublisherRelationship: false));

        $this->migrateTo(self::model(withPublisherRelationship: true));

        $this->assertContains(
            "FK_Manuscript_Publisher",
            $this->foreignKeyNames("Manuscript"),
            "adding a to-one relationship creates the FK_<table>_<Relationship> constraint",
        );
    }

    /**
     * The expected value is a set of acceptable INFORMATION_SCHEMA DELETE_RULE strings: InnoDB
     * treats RESTRICT and NO ACTION as identical and reports both as "NO ACTION", so the deny
     * (RESTRICT) and no-action cases each accept either spelling.
     *
     * @return iterable<string, array{DeleteRule, list<string>}>
     */
    public static function deleteRuleProvider(): iterable
    {
        yield "nullify -> SET NULL" => [DeleteRule::nullifyDeleteRule, ["SET NULL"]];
        yield "cascade -> CASCADE" => [DeleteRule::cascadeDeleteRule, ["CASCADE"]];
        yield "deny -> RESTRICT/NO ACTION" => [DeleteRule::denyDeleteRule, ["RESTRICT", "NO ACTION"]];
        yield "noAction -> NO ACTION" => [DeleteRule::noActionDeleteRule, ["RESTRICT", "NO ACTION"]];
    }

    /**
     * @param list<string> $acceptableActions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider("deleteRuleProvider")]
    public function testForeignKeyConstraintHonorsTheInverseDeleteRule(DeleteRule $rule, array $acceptableActions): void
    {
        $this->bootstrap(self::model(withPublisherRelationship: false));

        $this->migrateTo(self::model(withPublisherRelationship: true, inverseDeleteRule: $rule));

        $this->assertContains(
            $this->deleteRuleFor("Manuscript", "FK_Manuscript_Publisher"),
            $acceptableActions,
            "the inverse relationship's delete rule maps to the FK's ON DELETE action",
        );
    }

    /**
     * Reads the ON DELETE action of a named foreign key from INFORMATION_SCHEMA.
     */
    private function deleteRuleFor(string $tableName, string $constraintName): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
        );
        $stmt->execute([static::DATABASE_NAME, $tableName, $constraintName]);
        $rule = $stmt->fetchColumn();
        return $rule === false ? null : (string)$rule;
    }
}
