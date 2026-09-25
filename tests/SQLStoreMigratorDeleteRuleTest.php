<?php

/** @noinspection PhpUndefinedFieldInspection */

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DeleteRule;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;

/**
 * @property string $code
 */
final class Silo extends ManagedObject
{
}

/**
 * @property string $label
 */
final class Sack extends ManagedObject
{
}

/**
 * Characterization tests for a delete rule that changes between two model versions.
 *
 * The foreign key's ON DELETE clause is derived from the INVERSE relationship's rule — a
 * to-one's foreign key reads its to-many inverse — so changing the to-many's rule is what
 * rewrites the DDL, by dropping the constraint and creating it again, since ON DELETE cannot be
 * altered in place.
 *
 * Changing the to-one's own rule reaches none of that. Measured, the migrator pairs the source
 * foreign key against the destination's SQLToOne rather than its SQLForeignKey — the to-one
 * intercepts the renaming-identifier match ahead of its own foreign key — so the FK-versus-FK
 * branch that compares to-one delete rules is never entered from here. The second test pins the
 * outcome that does hold: the migration completes and leaves the constraint and the link intact.
 *
 * Model: Silo -1----*- Sack, so the FK "siloID" lives on the Sack table and its ON DELETE
 * follows the "sacks" to-many.
 */
final class SQLStoreMigratorDeleteRuleTest extends SQLMigrationTestCase
{
    /** @noinspection PhpSameParameterValueInspection */
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        return $attribute;
    }

    /**
     * Silo with a to-many "sacks" under $toManyRule, and Sack with its to-one inverse "silo"
     * under $toOneRule.
     */
    private static function model(DeleteRule $toManyRule, DeleteRule $toOneRule): ManagedObjectModel
    {
        $sacks = new RelationshipDescription();
        $sacks->name = "sacks";
        $sacks->lazyDestinationEntityName = "Sack";
        $sacks->lazyInverseRelationshipName = "silo";
        $sacks->isToMany = true;
        $sacks->deleteRule = $toManyRule;

        $silo = new EntityDescription();
        $silo->name = "Silo";
        $silo->managedObjectClassName = Silo::class;
        $silo->properties = new ArrayClass([self::attribute("code", AttributeType::string), $sacks]);

        $inverse = new RelationshipDescription();
        $inverse->name = "silo";
        $inverse->lazyDestinationEntityName = "Silo";
        $inverse->lazyInverseRelationshipName = "sacks";
        $inverse->maxCount = 1;
        $inverse->deleteRule = $toOneRule;

        $sack = new EntityDescription();
        $sack->name = "Sack";
        $sack->managedObjectClassName = Sack::class;
        $sack->properties = new ArrayClass([self::attribute("label", AttributeType::string), $inverse]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$silo, $sack]);
        return $model;
    }

    /**
     * The ON DELETE clause of the foreign key on $tableName, as the server reports it, or null
     * when no such constraint exists.
     * @noinspection PhpSameParameterValueInspection
     */
    private function deleteRuleOf(string $tableName): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?",
        );
        $statement->execute([self::DATABASE_NAME, $tableName]);
        $rule = $statement->fetchColumn();
        return $rule === false ? null : (string)$rule;
    }

    /**
     * Changing the to-many's rule rewrites the foreign key's ON DELETE, which is only possible
     * by dropping the constraint and creating it again.
     *
     * @throws Exception
     */
    public function testChangingTheToManyRuleRewritesTheForeignKeyOnDeleteClause(): void
    {
        $context = $this->bootstrap(self::model(DeleteRule::nullifyDeleteRule, DeleteRule::nullifyDeleteRule));
        $silo = new Silo($context);
        $silo->code = "S1";
        $sack = new Sack($context);
        $sack->label = "bag";
        $sack->silo = $silo;
        $context->save();

        $this->assertSame("SET NULL", $this->deleteRuleOf("Sack"), "precondition: nullify maps to SET NULL");

        $this->migrateTo(self::model(DeleteRule::cascadeDeleteRule, DeleteRule::nullifyDeleteRule));

        $this->assertSame("CASCADE", $this->deleteRuleOf("Sack"), "the constraint is recreated under the new rule");
    }

    /**
     * Changing only the to-one's rule alters the model's version hash, so a migration does run,
     * but it neither reaches the FK-versus-FK branch nor changes the emitted DDL. What must hold
     * is that it leaves a usable constraint rather than dropping it and stopping.
     *
     * @throws Exception
     */
    public function testChangingTheToOneRuleLeavesTheConstraintIntact(): void
    {
        $context = $this->bootstrap(self::model(DeleteRule::nullifyDeleteRule, DeleteRule::nullifyDeleteRule));
        $silo = new Silo($context);
        $silo->code = "S1";
        $sack = new Sack($context);
        $sack->label = "bag";
        $sack->silo = $silo;
        $context->save();

        $reference = $this->columnValues("Sack", "siloID");
        $this->assertSame(["1"], $reference, "precondition: the link is stored");

        $this->migrateTo(self::model(DeleteRule::nullifyDeleteRule, DeleteRule::denyDeleteRule));

        $this->assertContains("FK_Sack_Silo", $this->foreignKeyNames("Sack"), "the constraint survives the migration");
        $this->assertSame("SET NULL", $this->deleteRuleOf("Sack"), "the clause still follows the unchanged to-many rule");
        $this->assertSame($reference, $this->columnValues("Sack", "siloID"), "the link is preserved");
    }
}
