<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Predicate;

final class Part extends ManagedObject
{
}

/**
 * Pins which operators escape SQL wildcards in a string constant.
 *
 * SQLGenerator::buildConstantValueExpression used to run addcslashes($value, "%_") on every
 * string constant, before the operator was known. That is required for the LIKE family — a
 * user writing LIKE "50%OFF" means a literal percent — but wrong for `=` and the ordinary
 * comparisons: MariaDB does not interpret a backslash in `=`, so the escaped literal
 * "AUDIT\_TEST" was compared against the real value "AUDIT_TEST" and matched nothing. The
 * result was silent row loss for any exact match on a string holding `_` or `%`, which is
 * common in SKUs, folios and part numbers.
 *
 * The escape is now threaded through as an explicit `escapesWildcards` flag that only the
 * LIKE-family prepare* methods set. These tests fetch through the model against a real
 * server, so they assert the end-to-end consequence (does the row come back) rather than
 * the shape of the generated SQL.
 */
final class SQLWildcardEscapingTest extends SQLMigrationTestCase
{
    /** Values holding literal SQL wildcards, which is what makes the escaping observable. */
    private const string UnderscoreSKU = "AUDIT_TEST_50";
    private const string PercentSKU = "50%OFF";
    private const string BothSKU = "AB_CD%EF";

    private static function model(): ManagedObjectModel
    {
        $sku = new AttributeDescription();
        $sku->name = "sku";
        $sku->type = AttributeType::string;

        $part = new EntityDescription();
        $part->name = "Part";
        $part->managedObjectClassName = Part::class;
        $part->properties = new ArrayClass([$sku]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$part]);
        return $model;
    }

    /**
     * Seeds one row per SKU, plus a decoy that a wildcard-interpreting query would match but an
     * exact comparison must not: if `_` were treated as "any character", "AUDIT_TEST_50" would
     * also match "AUDITxTESTx50".
     */
    private function seed(): void
    {
        $context = $this->bootstrap(self::model());
        foreach ([self::UnderscoreSKU, self::PercentSKU, self::BothSKU, "AUDITxTESTx50", "50NOTOFF"] as $value) {
            $part = new Part($context);
            $part->sku = $value;
        }
        $context->save();
    }

    /**
     * @return list<string> the sku of every Part matching $predicate, fetched through the model
     */
    private function fetchSKUs(string $predicate): array
    {
        $request = Part::fetchRequest();
        $request->predicate = Predicate::format($predicate);
        $results = $this->freshContext(self::model())->fetch($request);
        $skus = [];
        foreach ($results as $part) {
            $skus[] = $part->sku;
        }
        sort($skus);
        return $skus;
    }

    /**
     * The regression: an exact match on a value containing `_` must return that row. Before the
     * fix the bound argument was "AUDIT\_TEST\_50" and this fetch returned nothing.
     */
    public function testExactMatchFindsValueContainingUnderscore(): void
    {
        $this->seed();
        $this->assertSame([self::UnderscoreSKU], $this->fetchSKUs(sprintf("sku == \"%s\"", self::UnderscoreSKU)));
    }

    /**
     * The same failure for `%`, the other character addcslashes was escaping.
     */
    public function testExactMatchFindsValueContainingPercent(): void
    {
        $this->seed();
        $this->assertSame([self::PercentSKU], $this->fetchSKUs(sprintf("sku == \"%s\"", self::PercentSKU)));
    }

    /**
     * An exact match stays exact: `_` is a literal, never a single-character wildcard, so the
     * decoy row "AUDITxTESTx50" must not come back.
     */
    public function testExactMatchDoesNotTreatUnderscoreAsWildcard(): void
    {
        $this->seed();
        $this->assertNotContains("AUDITxTESTx50", $this->fetchSKUs(sprintf("sku == \"%s\"", self::UnderscoreSKU)));
    }

    /**
     * != is routed through the same comparison path and must agree with ==: the row whose sku
     * equals the value is the one row excluded.
     */
    public function testNotEqualExcludesOnlyTheEscapedValue(): void
    {
        $this->seed();
        $this->assertSame(["50%OFF", "50NOTOFF", "AB_CD%EF", "AUDITxTESTx50"], $this->fetchSKUs(sprintf("sku != \"%s\"", self::UnderscoreSKU)));
    }

    /**
     * The other half of the contract: the LIKE family still escapes, so a pattern containing
     * `_` and `%` matches them literally and does not behave as a wildcard search.
     */
    public function testLikeTreatsWildcardsInThePatternAsLiterals(): void
    {
        $this->seed();
        $this->assertSame([self::BothSKU], $this->fetchSKUs(sprintf("sku LIKE \"%s\"", self::BothSKU)));
    }

    /**
     * BEGINSWITH appends its own trailing wildcard but must still escape the ones inside the
     * user's value, matching the literal prefix only.
     */
    public function testBeginsWithEscapesWildcardsInsideTheValue(): void
    {
        $this->seed();
        $this->assertSame([self::UnderscoreSKU], $this->fetchSKUs("sku BEGINSWITH \"AUDIT_TEST\""));
    }

    /**
     * CONTAINS wraps the value in wildcards on both sides; the `%` inside the value stays
     * literal, so "50NOTOFF" is not a match.
     */
    public function testContainsEscapesWildcardsInsideTheValue(): void
    {
        $this->seed();
        $this->assertSame([self::PercentSKU], $this->fetchSKUs("sku CONTAINS \"50%\""));
    }

    /**
     * IN parameterizes its list directly rather than going through the comparison path, so it
     * never escaped; this pins that it agrees with == on the same values.
     */
    public function testInMatchesValuesContainingWildcards(): void
    {
        $this->seed();
        $this->assertSame([self::PercentSKU, self::UnderscoreSKU], $this->fetchSKUs(sprintf("sku IN {\"%s\", \"%s\"}", self::UnderscoreSKU, self::PercentSKU)));
    }
}
