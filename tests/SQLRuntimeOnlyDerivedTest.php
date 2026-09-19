<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DerivedAttributeDescription;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SQLCore;
use Sabatier\CoreData\SQLDebugLevel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;

/**
 * @property string $code
 * @property int $lineCount
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
 * Two derivations that both report isRuntimeOnly and reach the server by different routes:
 * "order.code" traverses a relationship and needs the LEFT JOIN that defines its alias, while
 * "lines.@count" becomes a correlated subquery carrying its own FROM and must not get one.
 *
 * Both matter. The traversal raised "Unknown column 'DerivedLine_order.code'" before the join
 * was emitted, and Singularity's Entity.indexesCount derives from "indexes.@count", so a filter
 * written against isRuntimeOnly alone takes the aggregate down with the traversal.
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
        $orderCode->isOptional = false;
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

        $lineCount = new DerivedAttributeDescription();
        $lineCount->name = "lineCount";
        $lineCount->type = AttributeType::integer32;
        $lineCount->isOptional = false;
        $lineCount->derivationExpression = Expression::expressionWithFormat("lines.@count");

        $orderEntity = new EntityDescription();
        $orderEntity->name = "DerivedOrder";
        $orderEntity->managedObjectClassName = DerivedOrder::class;
        $orderEntity->properties = new ArrayClass([self::attribute("code", AttributeType::string), $lineCount, $lines]);

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

    /** The SQL the store logged while $body ran. */
    private function sqlDuring(callable $body): string
    {
        $logPath = tempnam(sys_get_temp_dir(), "coredata-sql-");
        $previousDestination = ini_get("error_log");
        $previousLogErrors = ini_get("log_errors");
        $previousLevel = SQLCore::$debugLevel;

        ini_set("error_log", $logPath);
        ini_set("log_errors", "1");
        SQLCore::$debugLevel = SQLDebugLevel::sqlWithParams;
        try {
            $body();
        } finally {
            SQLCore::$debugLevel = $previousLevel;
            ini_set("error_log", $previousDestination === false ? "" : $previousDestination);
            ini_set("log_errors", $previousLogErrors === false ? "" : $previousLogErrors);
        }
        $log = file_get_contents($logPath) ?: "";
        unlink($logPath);
        return $log;
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
     * The path that raised "Unknown column": the fault builds its own fetch over DerivedLine, so
     * the join has to come from the serialization rather than from a predicate or sort a caller
     * wrote.
     *
     * @throws Exception
     */
    public function testResolvingTheRelationshipAsksForTheDerivation(): void
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

        $this->assertSame("O-1", $line->orderCode, "the derivation answers with the owner's value");
    }

    /**
     * Excluding the aggregate leaves a caller reading zero where the store knows the real
     * number, which is how Singularity's editor stopped showing any indexes.
     *
     * @throws Exception
     */
    public function testAnAggregateDerivationIsFetched(): void
    {
        $this->seed();

        $fresh = $this->freshContext(self::model());
        $request = DerivedOrder::fetchRequest();
        $order = $fresh->fetch($request)->first;
        $this->assertInstanceOf(DerivedOrder::class, $order);

        $this->assertTrue($request->serialization->keys->containsElement("lineCount"), "the aggregate is among the properties the fetch asks for");
        $this->assertSame(1, $order->lineCount, "and it counted the line the store holds");
    }

    /**
     * Asserting the statement rather than the value is the point: reading the attribute computes
     * it in PHP either way, so only the SQL says whether the server was asked.
     *
     * @throws Exception
     */
    public function testTheTraversingDerivationIsFetchedThroughAJoin(): void
    {
        $this->seed();

        $request = DerivedLine::fetchRequest();
        $request->entity = self::model()->entitiesByName["DerivedLine"];
        $this->assertTrue($request->serialization->keys->containsElement("orderCode"), "the traversal is among the properties the fetch asks for");

        $fresh = $this->freshContext(self::model());
        $sql = $this->sqlDuring(fn() => $fresh->fetch($request));

        $this->assertStringContainsString("DerivedLine_order.code AS orderCode", $sql, "the derivation is selected through the relationship's alias");
        $this->assertStringContainsString("LEFT JOIN `DerivedOrder` AS DerivedLine_order", $sql, "and the join that defines that alias is emitted");
    }

    /**
     * The aggregate must not acquire a join: its subquery carries its own FROM, and joining
     * "lines" into the outer query would multiply DerivedOrder's rows by its lines.
     *
     * @throws Exception
     */
    public function testTheAggregateDerivationIsFetchedWithoutAJoin(): void
    {
        $this->seed();

        $fresh = $this->freshContext(self::model());
        $sql = $this->sqlDuring(fn() => $fresh->fetch(DerivedOrder::fetchRequest()));

        $this->assertStringContainsString("AS lineCount", $sql, "the aggregate is selected");
        $this->assertStringNotContainsString("JOIN `DerivedLine`", $sql, "and no join was added for it");
    }

    /**
     * A serialization that reaches a second entity carries that entity's derivations too, and
     * their joins hang off the joined alias rather than the root table — "order" read from
     * DerivedOrder_lines is DerivedOrder_lines_order, not DerivedLine_order.
     *
     * @throws Exception
     */
    public function testANestedDerivationJoinsFromTheJoinedAlias(): void
    {
        $this->seed();

        $model = self::model();
        $request = DerivedOrder::fetchRequest();
        $request->entity = $model->entitiesByName["DerivedOrder"];
        $request->propertiesToFetch = new ArrayClass([$request->entity->propertiesByName["lines"]]);

        $fresh = $this->freshContext($model);
        $sql = $this->sqlDuring(fn() => $fresh->fetch($request));

        $this->assertStringContainsString("LEFT JOIN `DerivedOrder` AS DerivedOrder_lines_order", $sql, "the nested derivation's join hangs off the joined alias");
    }

    /**
     * A count asks for no property values, so the derivation is never selected and its join must
     * not be emitted either: a to-one join is harmless to a count, but the rule is that the join
     * follows the SELECT.
     *
     * @throws Exception
     */
    public function testACountFetchJoinsNothingForTheDerivation(): void
    {
        $this->seed();

        $model = self::model();
        $request = DerivedLine::fetchRequest();
        $request->entity = $model->entitiesByName["DerivedLine"];
        $request->resultType = FetchRequestResultType::countResultType;

        $fresh = $this->freshContext($model);
        $sql = $this->sqlDuring(fn() => $fresh->count($request));

        $this->assertStringNotContainsString("JOIN", $sql, "a count joins nothing");
    }

    /**
     * A transient derivation is skipped by the SELECT (SQLGenerator only emits a non-transient
     * SQLAttribute), so it must be skipped here too. propertiesToFetch does not filter transients
     * the way the default serialization does, so the caller can put one in the shape.
     *
     * @throws Exception
     */
    public function testATransientDerivationJoinsNothing(): void
    {
        $this->seed();

        $model = self::model();
        $entity = $model->entitiesByName["DerivedLine"];
        /** @var DerivedAttributeDescription $orderCode */
        $orderCode = $entity->attributesByName["orderCode"];
        $orderCode->isTransient = true;

        $request = DerivedLine::fetchRequest();
        $request->entity = $entity;
        $request->propertiesToFetch = new ArrayClass([$orderCode]);

        $fresh = $this->freshContext($model);
        $sql = $this->sqlDuring(fn() => $fresh->fetch($request));

        $this->assertStringNotContainsString("JOIN", $sql, "a transient derivation is not selected, so it gets no join");
    }

    /**
     * The two are told apart by the key-value operator, not by isRuntimeOnly, which both report.
     *
     * @throws Exception
     */
    public function testTheTwoDerivationsDifferByTheirKeyValueOperator(): void
    {
        $model = self::model();
        /** @var DerivedAttributeDescription $traversing */
        $traversing = $model->entitiesByName["DerivedLine"]?->attributesByName["orderCode"];
        /** @var DerivedAttributeDescription $aggregate */
        $aggregate = $model->entitiesByName["DerivedOrder"]?->attributesByName["lineCount"];

        $this->assertTrue($traversing->isRuntimeOnly, "both are runtime-only");
        $this->assertTrue($aggregate->isRuntimeOnly, "both are runtime-only");
        $this->assertFalse($traversing->usesKeyValueOperator, "the traversal has no operator, so it is reached through a join");
        $this->assertTrue($aggregate->usesKeyValueOperator, "the aggregate has one, so it becomes a correlated subquery");
    }
}
