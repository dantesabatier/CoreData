<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;

/**
 * @property string $title
 */
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
    private const array Titles = ["Adagio", "adagio", "ADAGIO", "Allegro", "allegro", "Bolero"];

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

    /** @throws Exception */
    private function seed(): void
    {
        $context = $this->bootstrap(self::model());
        foreach (self::Titles as $value) {
            $track = new Track($context);
            $track->title = $value;
        }
        $context->save();
    }

    /**
     * @return list<string> the title of every Track matching $predicate, fetched through the store
     * @throws Exception
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
     * Evaluates the predicate against dictionaries keyed by "title" rather than against bare
     * strings, so the `title` key path actually resolves. Foundation falls back to SELF for a
     * key path an object cannot answer, which makes a bare string agree by accident for most
     * operators — but it hides whether the key path was resolved at all.
     *
     * @return list<string> the titles the same predicate selects when evaluated in memory
     */
    private static function evaluateTitles(string $predicate): array
    {
        $compiled = Predicate::format($predicate);
        $titles = [];
        foreach (self::Titles as $title) {
            if ($compiled->evaluate(new Dictionary(["title" => $title]))) {
                $titles[] = $title;
            }
        }
        sort($titles);
        return $titles;
    }

    /**
     * Asserts the store and the in-memory evaluator select the same titles, then returns them
     * so the caller can pin the actual expected set too. Agreement alone is not enough: both
     * layers could be wrong in the same direction.
     *
     * @return list<string>
     * @throws Exception
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
     *
     * @throws Exception
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
     *
     * @throws Exception
     */
    public function testBeginsWithMatchesOnlyTheCorrectlyCasedRow(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO"], $this->assertLayersAgree("title BEGINSWITH \"AD\""));
    }

    /**
     * The `[c]` option asks for case-insensitivity, and then every casing matches.
     *
     * @throws Exception
     */
    public function testBeginsWithCaseInsensitiveOptionMatchesEveryCasing(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO", "Adagio", "adagio"], $this->assertLayersAgree("title BEGINSWITH[c] \"ad\""));
    }

    /**
     * CONTAINS is a bare `LIKE BINARY` (no companion term — a pattern with no leading anchor
     * cannot range-scan anyway) and is case-sensitive: no row contains "LLEG" in upper case.
     *
     * @throws Exception
     */
    public function testContainsIsCaseSensitiveByDefault(): void
    {
        $this->seed();
        $this->assertSame([], $this->assertLayersAgree("title CONTAINS \"LLEG\""));
    }

    /** @throws Exception */
    public function testContainsCaseInsensitiveOptionMatchesEveryCasing(): void
    {
        $this->seed();
        $this->assertSame(["Allegro", "allegro"], $this->assertLayersAgree("title CONTAINS[c] \"LLEG\""));
    }

    /**
     * ENDSWITH, same contract: only the row whose suffix matches byte for byte.
     *
     * @throws Exception
     */
    public function testEndsWithIsCaseSensitiveByDefault(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO"], $this->assertLayersAgree("title ENDSWITH \"GIO\""));
    }

    /** @throws Exception */
    public function testEndsWithCaseInsensitiveOptionMatchesEveryCasing(): void
    {
        $this->seed();
        $this->assertSame(["ADAGIO", "Adagio", "adagio"], $this->assertLayersAgree("title ENDSWITH[c] \"GIO\""));
    }

    /**
     * LIKE is case-sensitive by default too, pinned with a wildcard-free pattern.
     *
     * A wildcard-free pattern is used because LIKE has no working wildcard in either layer; see
     * testLikeHasNoWildcardInEitherLayer.
     *
     * @throws Exception
     */
    public function testLikeIsCaseSensitiveByDefault(): void
    {
        $this->seed();
        $this->assertSame(["Bolero"], $this->fetchTitles("title LIKE \"Bolero\""));
        $this->assertSame([], $this->fetchTitles("title LIKE \"BOLERO\""));
    }

    /** @throws Exception */
    public function testLikeCaseInsensitiveOptionIgnoresCase(): void
    {
        $this->seed();
        $this->assertSame(["Bolero"], $this->fetchTitles("title LIKE[c] \"BOLERO\""));
    }

    /**
     * LIKE has NO wildcard through either layer, by two independent mechanisms that happen to
     * agree.
     *
     * In memory, Foundation routes LIKE through LikePredicateOperator -> MatchingPredicateOperator,
     * which forces CompareOptions::quoted before calling string_matches — so the pattern is
     * preg_quote'd and the anchored regex it compiles to matches literally. `Bol.*` does not match
     * "Bolero"; not even `.*` matches anything.
     *
     * Through the store, LIKE is emitted as SQL `LIKE BINARY`, whose wildcards are `%` and `_` —
     * but prepareLike passes escapesWildcards: true, so the user's own `%` is escaped to `\%` and
     * bound as a literal percent.
     *
     * So a wildcarded LIKE matches nothing on either side, in either syntax, and the two layers
     * agree on every pattern. The escaping is not an oversight: it is what makes an exact-looking
     * LIKE on a value containing a literal `%` or `_` work (see SQLWildcardEscapingTest). The cost
     * is that LIKE offers no pattern matching at all — it is an exact comparison with extra steps.
     * A caller who wants a wildcard search should use BEGINSWITH / CONTAINS / ENDSWITH, and one
     * who wants a regex should use MATCHES.
     *
     * @throws Exception
     */
    public function testLikeHasNoWildcardInEitherLayer(): void
    {
        $this->seed();

        // The SQL wildcard is escaped to a literal before it reaches the database...
        $this->assertSame([], $this->assertLayersAgree("title LIKE \"Bol%\""));
        // ...and the regex wildcard is preg_quote'd before the in-memory matcher sees it.
        $this->assertSame([], $this->assertLayersAgree("title LIKE \"Bol.*\""));

        // What LIKE does match is the exact string, in both layers.
        $this->assertSame(["Bolero"], $this->assertLayersAgree("title LIKE \"Bolero\""));
    }

    /**
     * The equivalence that licenses the companion-term optimisation on BEGINSWITH: for a needle
     * that is a whole title, the compound clause must agree with ENDSWITH on the same string,
     * which is a plain `LIKE BINARY` with no companion term.
     *
     * @throws Exception
     */
    public function testBeginsWithAgreesWithBinaryOnlyOperator(): void
    {
        $this->seed();
        $this->assertSame($this->fetchTitles("title ENDSWITH \"Adagio\""), $this->fetchTitles("title BEGINSWITH \"Adagio\""));
    }
}
