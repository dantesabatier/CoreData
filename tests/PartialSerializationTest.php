<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DerivedAttributeDescription;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Expression;

final class Tally extends ManagedObject
{
}

/**
 * Tests that a fetch asking for a narrow serialization does not poison the ones that follow.
 *
 * A fetch only materializes the attributes its serialization asks for. The object left behind
 * carries the rest at their default values, and for a non-optional numeric attribute that default
 * is 0 — indistinguishable, on inspection alone, from a value legitimately stored as zero. A later
 * fetch asking for more used to accept that object as complete and drop the values it had just
 * read from the store, so the second screen rendered zeros until the row cache was flushed.
 *
 * This runs against a real SQL store because the behaviour lives there: only SQLFetchRequestContext
 * narrows the columns it selects to the serialization and folds the result into an already
 * materialized object. The XML store rebuilds objects wholesale, so it cannot exercise any of it.
 *
 * The model mirrors the shape that exposed the bug: a name every view asks for, plus an amount and
 * a derived total only one view asks for, both non-optional so their unset state is a zero, not a
 * null. The derived one is what the failing screen actually showed — a value the database computes,
 * which the object cannot rebuild on its own once it has wrongly concluded it already holds it.
 */
final class PartialSerializationTest extends SQLMigrationTestCase
{
    /** The narrow shape, as an analytics-style view asks for it. */
    private const array NarrowShape = ["name" => AttributeType::string];

    /** The wide shape, as the screen that shows the figures asks for it. */
    private const array WideShape = [
        "name" => AttributeType::string,
        "amount" => AttributeType::float,
        "doubled" => AttributeType::float,
    ];

    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $amount = new AttributeDescription();
        $amount->name = "amount";
        $amount->type = AttributeType::float;
        $amount->isOptional = false;
        $amount->defaultValue = 0;

        // Derived and non-optional, like the totals the screen shows: unloaded it reads as a zero identical to a total that genuinely is zero.
        $doubled = new DerivedAttributeDescription();
        $doubled->name = "doubled";
        $doubled->type = AttributeType::float;
        $doubled->isOptional = false;
        $doubled->defaultValue = 0;
        $doubled->derivationExpression = Expression::expressionForFunction("multiply:by:", new ArrayClass([Expression::expressionForKeyPath("amount"), Expression::expressionForConstantValue(2)]));

        $entity = new EntityDescription();
        $entity->name = "Tally";
        $entity->managedObjectClassName = Tally::class;
        $entity->properties = new ArrayClass([$name, $amount, $doubled]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private function seed(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::model());
        $tally = new Tally($context);
        $tally->name = "Acme";
        $tally->amount = 1250.5;
        $context->save();
        return $context;
    }

    /**
     * A fetch resolves its entity through the context associated with the running queue, so the
     * request has to be issued from inside one.
     *
     * @param array<string, AttributeType> $shape
     */
    private function fetch(ManagedObjectContext $context, array $shape): ManagedObject
    {
        $fetched = null;
        $context->performBlockAndWait(function () use ($context, $shape, &$fetched): void {
            $request = new FetchRequest("Tally");
            $request->serialization = Dictionary::dictionaryWithArray($shape);
            $fetched = $context->fetch($request)->first;
        });
        return $fetched;
    }

    public function testAWiderFetchRecoversTheAttributesANarrowerOneLeftBehind(): void
    {
        $context = $this->seed();
        $this->fetch($context, self::NarrowShape);
        $tally = $this->fetch($context, self::WideShape);

        $this->assertEqualsWithDelta(1250.5, $tally->valueForKey("amount"), 0.0001, "the wider fetch must keep the amount it read from the store");
        $this->assertEqualsWithDelta(2501.0, $tally->valueForKey("doubled"), 0.0001, "the wider fetch must keep the derived value it read from the store");
    }

    public function testANarrowerFetchDoesNotDiscardWhatIsAlreadyLoaded(): void
    {
        $context = $this->seed();
        $this->fetch($context, self::WideShape);
        $tally = $this->fetch($context, self::NarrowShape);

        $this->assertEqualsWithDelta(1250.5, $tally->valueForKey("amount"), 0.0001, "a narrower fetch must not blank an attribute already loaded");
        $this->assertEqualsWithDelta(2501.0, $tally->valueForKey("doubled"), 0.0001, "a narrower fetch must not blank an attribute already loaded");
    }

    public function testAStoredZeroIsNotMistakenForAMissingValue(): void
    {
        $context = $this->seed();
        $tally = $this->fetch($context, self::WideShape);
        $tally->amount = 0.0;
        $context->save();

        // A stored zero is the edge case: were the fix to treat "holds zero" as "not loaded", the wider re-read would keep going back to the store forever.
        $reread = $this->fetch($context, self::NarrowShape);
        $this->assertEqualsWithDelta(0.0, $this->fetch($context, self::WideShape)->valueForKey("amount"), 0.0001, "a stored zero is a real value, not a missing one");
        $this->assertSame("Acme", $reread->valueForKey("name"));
    }
}
