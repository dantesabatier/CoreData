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

final class Track extends ManagedObject
{
}

/**
 * Pins the case-sensitivity contract of the string operators, and that SQL agrees with the
 * in-memory evaluation of the same predicate.
 *
 * Foundation compares case-sensitively unless the predicate carries the `[c]` option, so the
 * SQL translation emits `LIKE BINARY` by default and drops the BINARY for `[c]`. The risk this
 * guards is a cross-layer disagreement: a predicate that filters one way in memory and another
 * way in the store returns different objects depending on whether the fetch was satisfied from
 * the row cache or the database.
 *
 * BEGINSWITH carries an extra wrinkle. `LIKE BINARY "prefix%"` compares in byte order, which
 * does not match a case-insensitive index's order, so it cannot range-scan. prepareBeginsWith
 * therefore emits `(col LIKE ? AND col LIKE BINARY ?)` — the ci term is an index-friendly
 * superset and the BINARY term refilters to the exact answer. That is a performance change
 * only, so what matters here is that the result set is identical to a bare `LIKE BINARY`.
 *
 * Note the fixture: the discriminating cases use an upper-case needle ("GIO", "LLEG"). A
 * lower-case needle such as "gio" matches BOTH "Adagio" and "adagio" case-sensitively, because
 * the lower-case substring is literally present in both — which makes it useless for telling a
 * case-sensitive operator from a case-insensitive one.
 */
final class SQLCaseSensitivityTest extends SQLMigrationTestCase
{
    /** @var list<string> Titles differing only in case, so casing changes the result set. */
    private const array TITLES = ["Adagio", "adagio", "ADAGIO", "Allegro", "allegro", "Bolero"];

    private static function model(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $track = new EntityDescription();
        $track->name = "Track";
        $track->managedObjectClassName = Track::class;
        $track->properties = new ArrayClass([$title]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$track]);
        return $model;
    }

    private function seed(): void
    {
        $context = $this->bootstrap(self::model());
        foreach (self::TITLES as $value) {
            $track = new Track($context);
            $track->title = $value;
        }
        $context->save();
    }

    /**
     * @return list<string> the title of every Track matching $predicate, fetched through the store
     */
    private function fetchTitles(string $predicate): array
    {
        $request = Track::fetchRequest();
        $request->predicate = Predicate::format($predicate);
        $results = $this->freshContext(self::model())->fetch($request);
        $titles = [];
        foreach ($results as $track) {
            $titles[] = $track->title;
        }
        sort($titles);
        return $titles;
    }

    /**
     * @return list<string> the titles the same predicate selects when evaluated in memory
     */
    private static function evaluateTitles(string $predicate): array
    {
        $compiled = Predicate::format($predicate);
        $titles = array_values(array_filter(self::TITLES, $compiled->evaluate(...)));
        sort($titles);
        return $titles;
    }

    /**
     * Asserts the store and the in-memory evaluator select the same titles, then returns them
     * so the caller can pin the actual expected set too. Agreement alone is not enough: both
     * layers could be wrong in the same direction.
     *
     * @return list<string>
     */
    private function assertLayersAgree(string $predicate): array
    {
        $fetched = $this->fetchTitles($predicate);
        $this->assertSame(self::evaluateTitles($predicate), $fetched, "SQL and in-memory evaluation must agree for: $predicate");
        return $fetched;
    }

    /**
     * The compound (ci + BINARY) clause stays case-sensitive: only the exactly-cased prefix
     * matches. If the companion ci term leaked through without the BINARY refilter, "adagio"
     * and "ADAGIO" would come back too.
     */
    public function testBeginsWithIsCaseSensitiveByDefault(): void
    {
        $this->seed();
        $this->assertSame(["Adagio"], $this->assertLayersAgree("title BEGINSWITH \"Ad\""));
    }

    /**
     * A prefix cased like no other row still matches the one row that does carry that casing —
     * "AD" is the real prefix of "ADAGIO" — which is what distinguishes a case-sensitive prefix
     * search from the ci superset the optimizer scans.
     */
    public function testBeginsWithMatchesOnlyTheCorrectlyCasedRow(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO"], $this->assertLayersAgree("title BEGINSWITH \"AD\""));
    }

    /**
     * The `[c]` option asks for case-insensitivity, and then every casing matches.
     */
    public function testBeginsWithCaseInsensitiveOptionMatchesEveryCasing(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO", "Adagio", "adagio"], $this->assertLayersAgree("title BEGINSWITH[c] \"ad\""));
    }

    /**
     * CONTAINS is a bare `LIKE BINARY` (no companion term — a pattern with no leading anchor
     * cannot range-scan anyway) and is case-sensitive: no row contains "LLEG" in upper case.
     */
    public function testContainsIsCaseSensitiveByDefault(): void
    {
        $this->seed();
        $this->assertSame([], $this->assertLayersAgree("title CONTAINS \"LLEG\""));
    }

    public function testContainsCaseInsensitiveOptionMatchesEveryCasing(): void
    {
        $this->seed();
        $this->assertSame(["Allegro", "allegro"], $this->assertLayersAgree("title CONTAINS[c] \"LLEG\""));
    }

    /**
     * ENDSWITH, same contract: only the row whose suffix matches byte for byte.
     */
    public function testEndsWithIsCaseSensitiveByDefault(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO"], $this->assertLayersAgree("title ENDSWITH \"GIO\""));
    }

    public function testEndsWithCaseInsensitiveOptionMatchesEveryCasing(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO", "Adagio", "adagio"], $this->assertLayersAgree("title ENDSWITH[c] \"GIO\""));
    }

    /**
     * LIKE is case-sensitive by default too, pinned with a wildcard-free pattern.
     *
     * Deliberately NOT asserted through assertLayersAgree: LIKE is the one operator whose
     * pattern language differs between the two layers, so agreement holds only while the
     * pattern contains no wildcard. See testLikePatternLanguageDivergesBetweenLayers.
     */
    public function testLikeIsCaseSensitiveByDefault(): void
    {
        $this->seed();
        $this->assertSame(["Bolero"], $this->fetchTitles("title LIKE \"Bolero\""));
        $this->assertSame([], $this->fetchTitles("title LIKE \"BOLERO\""));
    }

    public function testLikeCaseInsensitiveOptionIgnoresCase(): void
    {
        $this->seed();
        $this->assertSame(["Bolero"], $this->fetchTitles("title LIKE[c] \"BOLERO\""));
    }

    /**
     * Documents a known cross-layer divergence rather than a desired behavior.
     *
     * In memory, Foundation routes LIKE through LikePredicateOperator -> MatchingPredicateOperator
     * -> string_matches, which compiles the pattern as an anchored REGEX (`/^pattern$/`), so the
     * wildcard is regex syntax: `Bol.*` matches, `Bol%` does not.
     *
     * Through the store, neither works. LIKE is emitted as SQL `LIKE BINARY`, whose wildcards are
     * `%` and `_` — but prepareLike passes escapesWildcards: true, so the user's own `%` is
     * escaped to `\%` and bound as a LITERAL percent. A regex `.*` means nothing to SQL either.
     * The net effect is that a wildcarded LIKE matches nothing through the store no matter which
     * language the caller writes in, and only a wildcard-free pattern behaves the same in both
     * layers.
     *
     * That escaping is not an oversight: it is what makes an exact-looking LIKE on a value
     * containing a literal `%` or `_` work (see SQLWildcardEscapingTest). The cost is that SQL's
     * own wildcards are unreachable through this operator. Pinned so the behavior is visible and
     * any future change to it breaks this test on purpose. A caller who needs a portable wildcard
     * search should use BEGINSWITH / CONTAINS / ENDSWITH, which agree across both layers.
     */
    public function testLikePatternLanguageDivergesBetweenLayers(): void
    {
        $this->seed();

        // Through the store, the user's "%" is escaped to a literal, so nothing matches...
        $this->assertSame([], $this->fetchTitles("title LIKE \"Bol%\""));
        // ...and in memory it fails too, because "%" is just a literal in a regex.
        $this->assertSame([], self::evaluateTitles("title LIKE \"Bol%\""));

        // The regex wildcard is the one the in-memory evaluator understands, and SQL does not.
        $this->assertSame([], $this->fetchTitles("title LIKE \"Bol.*\""));
        $this->assertSame(["Bolero"], self::evaluateTitles("title LIKE \"Bol.*\""));

        // The only pattern both layers agree on is one with no wildcard at all.
        $this->assertSame(["Bolero"], $this->fetchTitles("title LIKE \"Bolero\""));
        $this->assertSame(["Bolero"], self::evaluateTitles("title LIKE \"Bolero\""));
    }

    /**
     * The equivalence that licenses the companion-term optimisation on BEGINSWITH: for a needle
     * that is a whole title, the compound clause must agree with ENDSWITH on the same string,
     * which is a plain `LIKE BINARY` with no companion term.
     */
    public function testBeginsWithAgreesWithBinaryOnlyOperator(): void
    {
        $this->seed();
        $this->assertSame($this->fetchTitles("title ENDSWITH \"Adagio\""), $this->fetchTitles("title BEGINSWITH \"Adagio\""));
    }
}
