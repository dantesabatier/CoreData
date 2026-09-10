<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;

/**
 * @property int $amount
 * @property string $note
 */
final class Ledger extends ManagedObject
{
}

/**
 * Regression test: when an existing attribute changes type (transformably) and, in the
 * destination model's declaration order, it is preceded by a NEWLY ADDED attribute, the early
 * "MODIFY ... AFTER <new column>" in the source loop referenced a column that had not been
 * created yet, crashing with "Unknown column". The final modify loop re-positions every
 * destination attribute after all columns exist, so the early positioned modify is redundant.
 */
final class SQLStoreMigratorModifyAfterTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        return $attribute;
    }

    /**
     * @param list<AttributeDescription> $attributes
     */
    private static function model(array $attributes): ManagedObjectModel
    {
        $ledger = new EntityDescription();
        $ledger->name = "Ledger";
        $ledger->managedObjectClassName = Ledger::class;
        $ledger->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$ledger]);
        return $model;
    }

    public function testTypeChangeAfterANewlyAddedColumnMigratesCleanly(): void
    {
        // v1: just "amount" (integer32).
        $context = $this->bootstrap(self::model([
            self::attribute("amount", AttributeType::integer32),
        ]));
        $ledger = new Ledger($context);
        $ledger->amount = 7;
        $context->save();

        // v2: a NEW "note" column declared BEFORE "amount", and amount widened to integer64.
        // The changed "amount" is preceded in declaration order by the not-yet-created "note".
        $this->migrateTo(self::model([
            self::attribute("note", AttributeType::string),
            self::attribute("amount", AttributeType::integer64),
        ]));

        $this->assertTrue($this->hasColumn("Ledger", "note"), "the new column is created");
        $this->assertSame("bigint", $this->columnType("Ledger", "amount"), "the widened column is migrated to BIGINT");
        $this->assertSame(
            ["7"],
            $this->columnValues("Ledger", "amount"),
            "the value survives the type change",
        );
    }
}
