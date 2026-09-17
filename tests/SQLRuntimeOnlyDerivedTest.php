<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DerivedAttributeDescription;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;

/**
 * @property string $code
 * @property Set<DerivedLine> $lines
 */
final class DerivedOrder extends ManagedObject
{
}

/**
 * @property string $concept
 * @property int $units
 * @property string $orderCode
 * @property DerivedOrder|null $order
 */
final class DerivedLine extends ManagedObject
{
}

/**
 * A derived attribute whose expression traverses a relationship is runtime-only: it has no
 * column, and its value is computed in PHP when something reads it.
 *
 * Three places decide what a fetch asks the server for, and two of them already excluded such an
 * attribute — SQLEntity::columnsToFetch and EntityDescription::persistentAttributeNames. The
 * third, the default serialization a fetch builds when no propertiesToFetch is given, did not, so
 * it put the derivation in the SELECT while nothing had joined the table it reads from. Resolving
 * a to-many raised "Unknown column 'DerivedLine_order.code'".
 */
final class SQLRuntimeOnlyDerivedTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        return $attribute;
    }

    /** Order -1----*- Line, where Line derives "orderCode" from "order.code". */
    private static function model(): ManagedObjectModel
    {
        $orderCode = new DerivedAttributeDescription();
        $orderCode->name = "orderCode";
        $orderCode->type = AttributeType::string;
        $orderCode->isOptional = true;
        $orderCode->derivationExpression = Expression::expressionForKeyPath("order.code");

        $order = new RelationshipDescription();
        $order->name = "order";
        $order->lazyDestinationEntityName = "DerivedOrder";
        $order->lazyInverseRelationshipName = "lines";
        $order->maxCount = 1;

        $line = new EntityDescription();
        $line->name = "DerivedLine";
        $line->managedObjectClassName = DerivedLine::class;
        $line->properties = new ArrayClass([
            self::attribute("concept", AttributeType::string),
            self::attribute("units", AttributeType::integer32),
            $orderCode,
            $order,
        ]);

        $lines = new RelationshipDescription();
        $lines->name = "lines";
        $lines->lazyDestinationEntityName = "DerivedLine";
        $lines->lazyInverseRelationshipName = "order";
        $lines->isToMany = true;

        $orderEntity = new EntityDescription();
        $orderEntity->name = "DerivedOrder";
        $orderEntity->managedObjectClassName = DerivedOrder::class;
        $orderEntity->properties = new ArrayClass([self::attribute("code", AttributeType::string), $lines]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$orderEntity, $line]);
        return $model;
    }

    /**
     * @throws Exception
     */
    private function seed(): void
    {
        $context = $this->bootstrap(self::model());
        $order = new DerivedOrder($context);
        $order->code = "O-1";
        $line = new DerivedLine($context);
        $line->concept = "first";
        $line->units = 3;
        $line->order = $order;
        $context->save();
    }

    /**
     * The attribute exists in the model but never becomes a column.
     *
     * @throws Exception
     */
    public function testARuntimeOnlyDerivationIsNotStored(): void
    {
        $this->seed();

        $this->assertTrue($this->hasColumn("DerivedLine", "units"), "precondition: an ordinary attribute is a column");
        $this->assertFalse($this->hasColumn("DerivedLine", "orderCode"), "a runtime-only derivation has no column");
    }

    /**
     * Resolving the relationship reaches the server, so a fetch that asked for the derivation
     * would be asking for a column that is not there.
     *
     * @throws Exception
     */
    public function testResolvingTheRelationshipDoesNotAskForTheDerivation(): void
    {
        $this->seed();

        $fresh = $this->freshContext(self::model());
        $order = $fresh->fetch(DerivedOrder::fetchRequest())->first;
        $this->assertInstanceOf(DerivedOrder::class, $order);

        $this->assertSame(1, $order->lines->count, "the to-many resolves");
    }

    /**
     * And the value is still there when something reads it, computed in PHP rather than fetched.
     *
     * @throws Exception
     */
    public function testTheDerivedValueIsComputedOnRead(): void
    {
        $this->seed();

        $fresh = $this->freshContext(self::model());
        $order = $fresh->fetch(DerivedOrder::fetchRequest())->first;
        $this->assertInstanceOf(DerivedOrder::class, $order);
        $line = $order->lines->first;
        $this->assertInstanceOf(DerivedLine::class, $line);

        $this->assertSame("O-1", (string)$line->orderCode, "the derivation answers with the owner's value");
    }
}
