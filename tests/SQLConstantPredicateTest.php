<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Predicate;

final class ConstantPredicateRow extends ManagedObject
{
}

/**
 * Covers the SQL translation of the constant predicates (`TRUEPREDICATE` / `FALSEPREDICATE`),
 * both standalone and folded into a compound predicate.
 *
 * These matter because a constant predicate is how a caller expresses "no rows" or "every row"
 * without a key path to compare, and because `AND`-folding one into an existing fetch is the
 * mechanism callers use to narrow a result set unconditionally. The SQL generator used to emit
 * nothing for them, which dropped the condition from the `WHERE` clause and widened the result
 * instead of narrowing it — a `FALSEPREDICATE` returned every row.
 */
final class SQLConstantPredicateTest extends SQLMigrationTestCase
{
    protected const string DATABASE_NAME = "coredata_constant_predicate_test";

    private static function makeModel(): ManagedObjectModel
    {
        $n = new AttributeDescription();
        $n->name = "n";
        $n->type = AttributeType::integer32;

        $row = new EntityDescription();
        $row->name = "ConstantPredicateRow";
        $row->managedObjectClassName = ConstantPredicateRow::class;
        $row->properties = new ArrayClass([$n]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$row]);
        return $model;
    }

    private function seededContext(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::makeModel());
        foreach ([1, 2, 3, 4, 5] as $value) {
            $row = new ConstantPredicateRow($context);
            $row->n = $value;
        }
        $context->save();
        return $context;
    }

    private static function request(Predicate $predicate): FetchRequest
    {
        $request = ConstantPredicateRow::fetchRequest();
        $request->predicate = $predicate;
        return $request;
    }

    public function testFalsePredicateReturnsNoRows(): void
    {
        $context = $this->seededContext();
        $this->assertCount(0, $context->fetch(self::request(Predicate::value(false))), "FALSEPREDICATE matches nothing");
    }

    public function testTruePredicateReturnsEveryRow(): void
    {
        $context = $this->seededContext();
        $this->assertCount(5, $context->fetch(self::request(Predicate::value(true))), "TRUEPREDICATE matches every row");
    }

    public function testFalsePredicateAndFoldedIntoAConditionReturnsNoRows(): void
    {
        $context = $this->seededContext();
        $predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([
            Predicate::format("n > %d", new ArrayClass([3])),
            Predicate::value(false),
        ]));

        $this->assertCount(0, $context->fetch(self::request($predicate)), "AND-folding FALSEPREDICATE narrows the result to nothing");
    }

    public function testTruePredicateAndFoldedIntoAConditionPreservesTheCondition(): void
    {
        $context = $this->seededContext();
        $predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([
            Predicate::format("n > %d", new ArrayClass([3])),
            Predicate::value(true),
        ]));

        $this->assertCount(2, $context->fetch(self::request($predicate)), "AND-folding TRUEPREDICATE leaves the other condition in force");
    }

    public function testConstantPredicateParsedFromItsFormatStringReturnsNoRows(): void
    {
        $context = $this->seededContext();
        $predicate = Predicate::format("FALSEPREDICATE") ?? self::fail("FALSEPREDICATE is valid predicate syntax");

        $this->assertCount(0, $context->fetch(self::request($predicate)), "the scanner's FALSEPREDICATE keyword reaches SQL as a false condition");
    }
}
