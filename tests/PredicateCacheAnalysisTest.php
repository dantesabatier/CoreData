<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\PredicateCacheAnalysis;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;

/**
 * Pins the cacheability decision in PredicateCacheAnalysis. This class decides whether a fetch's
 * results may be stored in the row cache: SQLCore::processFetchRequest only caches when
 * isRuntimeOnly is false. A wrong "false" caches results that depend on runtime state (variables,
 * volatile matching, non-deterministic functions, KVC/deep traversal) and serves them stale — a
 * correctness bug that surfaces as intermittent wrong query results. A wrong "true" merely
 * disables caching (a perf regression). Both directions matter, so every flag is pinned in both
 * states, and each is toggled by exactly one predicate feature so a regression localizes.
 *
 * The flag semantics were verified against the live analyser before writing these assertions.
 */
final class PredicateCacheAnalysisTest extends TestCase
{
    private static function keyPath(string $path): Expression
    {
        return Expression::expressionForKeyPath($path);
    }

    private static function constant(mixed $value): Expression
    {
        return Expression::expressionForConstantValue($value);
    }

    // --- The cacheable baseline: a plain deterministic comparison on a shallow key path ---

    public function testPlainComparisonIsCacheable(): void
    {
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(self::keyPath("name"), self::constant("Ada")),
        );

        $this->assertTrue($analysis->isDeterministic, "a constant comparison is deterministic");
        $this->assertFalse($analysis->hasVolatileOperators);
        $this->assertFalse($analysis->usesDeepKeyPaths);
        $this->assertFalse($analysis->usesKVCOperators);
        $this->assertFalse($analysis->hasRuntimeCollections);
        $this->assertFalse($analysis->isRuntimeOnly, "a plain comparison may be cached");
    }

    public function testConjunctionOfPlainComparisonsIsCacheable(): void
    {
        // A compound predicate of two cacheable comparisons stays cacheable — the analysis
        // visits the whole tree, so nesting alone must not trip any flag.
        $predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([
            new ComparisonPredicate(self::keyPath("name"), self::constant("Ada")),
            new ComparisonPredicate(self::keyPath("age"), self::constant(42), PredicateOperatorType::greaterThan),
        ]));

        $this->assertFalse(new PredicateCacheAnalysis($predicate)->isRuntimeOnly, "AND of cacheable terms stays cacheable");
    }

    // --- Volatile (pattern/range) operators: not cacheable ---

    /**
     * @return iterable<string, array{PredicateOperatorType}>
     */
    public static function volatileOperatorProvider(): iterable
    {
        yield "LIKE" => [PredicateOperatorType::like];
        yield "CONTAINS" => [PredicateOperatorType::contains];
        yield "BEGINSWITH" => [PredicateOperatorType::beginsWith];
        yield "ENDSWITH" => [PredicateOperatorType::endsWith];
        yield "MATCHES" => [PredicateOperatorType::matches];
    }

    #[DataProvider("volatileOperatorProvider")]
    public function testPatternOperatorsAreVolatileAndNotCacheable(PredicateOperatorType $operator): void
    {
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(self::keyPath("name"), self::constant("A%"), $operator),
        );

        $this->assertTrue($analysis->hasVolatileOperators, "a pattern operator is volatile");
        $this->assertTrue($analysis->isRuntimeOnly, "a volatile operator disables caching");
        // A pattern operator is the ONLY feature here — no other flag should fire.
        $this->assertFalse($analysis->usesDeepKeyPaths);
        $this->assertFalse($analysis->hasRuntimeCollections);
        $this->assertTrue($analysis->isDeterministic);
    }

    public function testBetweenIsVolatileAndNotCacheable(): void
    {
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(self::keyPath("age"), self::constant(new ArrayClass([1, 99])), PredicateOperatorType::between),
        );

        $this->assertTrue($analysis->hasVolatileOperators, "BETWEEN is treated as volatile");
        $this->assertTrue($analysis->isRuntimeOnly);
    }

    // --- Deep key paths: not cacheable ---

    public function testDeepKeyPathIsNotCacheable(): void
    {
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(self::keyPath("employer.name"), self::constant("Acme")),
        );

        $this->assertTrue($analysis->usesDeepKeyPaths, "a multi-segment key path is deep");
        $this->assertTrue($analysis->isRuntimeOnly);
        $this->assertFalse($analysis->usesKVCOperators, "a plain relationship traversal is not a KVC operator");
    }

    // --- KVC collection operators: not cacheable ---

    public function testKVCOperatorIsNotCacheable(): void
    {
        // "@count" over a to-many key path is a KVC collection operator. It is also a multi-
        // segment path, so usesDeepKeyPaths is expected to fire too; the point of this test is
        // that usesKVCOperators specifically detects the "@"-operator.
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(self::keyPath("employees.@count"), self::constant(3)),
        );

        $this->assertTrue($analysis->usesKVCOperators, "@count is a KVC collection operator");
        $this->assertTrue($analysis->isRuntimeOnly);
    }

    // --- Runtime collections: variables and subqueries are not cacheable ---

    public function testVariableExpressionIsRuntimeOnly(): void
    {
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(self::keyPath("name"), Expression::expressionForVariable("NAME")),
        );

        $this->assertTrue($analysis->hasRuntimeCollections, "a $-variable binds a runtime value");
        $this->assertTrue($analysis->isRuntimeOnly);
    }

    public function testSubqueryExpressionIsRuntimeOnly(): void
    {
        $subquery = Expression::expressionForSubquery(
            self::keyPath("employees"),
            Expression::expressionForVariable("x"),
            new ComparisonPredicate(self::keyPath("\$x.salary"), self::constant(1000), PredicateOperatorType::greaterThan),
        );
        // SUBQUERY(...).@count > 0 — the left side is the subquery expression.
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate($subquery, self::constant(0), PredicateOperatorType::greaterThan),
        );

        $this->assertTrue($analysis->hasRuntimeCollections, "a subquery is a runtime collection");
        $this->assertTrue($analysis->isRuntimeOnly);
    }

    // --- Non-deterministic functions: not cacheable ---

    public function testNonDeterministicFunctionIsNotCacheable(): void
    {
        // now() has no stable value, so its result must never be cached.
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(self::keyPath("createdAt"), Expression::expressionForFunction("now:", new ArrayClass())),
        );

        $this->assertFalse($analysis->isDeterministic, "now() is non-deterministic");
        $this->assertTrue($analysis->isRuntimeOnly);
    }

    public function testDeterministicFunctionStaysCacheable(): void
    {
        // A deterministic scalar function (uppercase) does not by itself disable caching.
        $analysis = new PredicateCacheAnalysis(
            new ComparisonPredicate(Expression::expressionForFunction("uppercase:", new ArrayClass([self::keyPath("name")])), self::constant("ADA")),
        );

        $this->assertTrue($analysis->isDeterministic, "uppercase() is deterministic");
        $this->assertFalse($analysis->hasVolatileOperators);
        $this->assertFalse($analysis->isRuntimeOnly, "a deterministic function keeps the predicate cacheable");
    }
}
