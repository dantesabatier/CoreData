<?php

/** @noinspection SqlDialectInspection, SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Sabatier\CoreData\SQLFormatter;
use Sabatier\CoreData\SQLFormatterStyle;
use Sabatier\CoreData\SQLFormatterToken;
use Sabatier\CoreData\SQLStatement;
use Sabatier\CoreData\SQLStatementFormatter;
use Sabatier\CoreData\SQLStatementFormatterStyle;
use Sabatier\Foundation\ArrayClass;

/**
 * Characterization tests for SQLFormatter, the debug pretty-printer and syntax highlighter.
 *
 * The formatter never runs in a query path — it exists so a logged statement is readable —
 * which is exactly why it had no tests: nothing fails when it misbehaves, the SQL in the log
 * just becomes wrong or unreadable. These tests pin the behavior as it stands today so a
 * refactor of the tokenizer has something to answer to.
 *
 * Two properties of the class shape how it can be asserted. Its only public surface is
 * string(), so token classification is observable solely through the colors the highlighted
 * style emits: the type-to-color map in SQLFormatter::highlighted() is the decoder, and
 * tokenTypeOf() below inverts it. And the tokenizer memoizes into a static cache keyed by a
 * 15-character prefix, so tests that touch the cache must restore it — see setUp/tearDown.
 *
 * Some fixtures here are not valid MariaDB on purpose — an unclosed parenthesis, a stray
 * closing one, a bracket-quoted identifier — because coping with malformed input is the
 * behavior under test: the formatter runs over whatever reaches the log. Those literals are
 * marked @lang text so the IDE does not parse them as SQL; leave those statements broken.
 */
final class SQLFormatterTest extends TestCase
{
    /**
     * ANSI foreground code => the token type(s) SQLFormatter::highlighted() paints with it.
     * Several types share a color; where a test needs to separate them it asserts on the
     * italic attribute too, which only the function type carries.
     */
    private const array ColorNames = [
        32 => "quote",
        97 => "boundary|function",
        33 => "reserved",
        90 => "comment|variable",
        94 => "number",
        91 => "warning",
        31 => "error",
        35 => "column",
        37 => "whitespace|word|backtickQuote",
    ];

    private int $maxCacheEntries;
    private int $maxCacheSize;
    private string $indent;

    /**
     * The tokenizer's memo and its three tuning knobs are static, so a test that changes one
     * would otherwise leak into every later test in the process.
     */
    #[Override]
    protected function setUp(): void
    {
        $this->maxCacheEntries = SQLFormatter::$maxCacheEntries;
        $this->maxCacheSize = SQLFormatter::$maxCacheSize;
        $this->indent = SQLFormatter::$indent;
        self::clearTokenCache();
    }

    #[Override]
    protected function tearDown(): void
    {
        SQLFormatter::$maxCacheEntries = $this->maxCacheEntries;
        SQLFormatter::$maxCacheSize = $this->maxCacheSize;
        SQLFormatter::$indent = $this->indent;
        self::clearTokenCache();
    }

    /**
     * The reflection cannot fail — tokenCache is declared on the class — so the exception is
     * swallowed here rather than propagated through every caller's signature.
     */
    private static function clearTokenCache(): void
    {
        try {
            new ReflectionClass(SQLFormatter::class)->getProperty("tokenCache")->setValue(null, []);
        } catch (ReflectionException $exception) {
            self::fail($exception->getMessage());
        }
    }

    /** @return array<string, SQLFormatterToken> */
    private static function tokenCache(): array
    {
        try {
            /** @var array<string, SQLFormatterToken> $cache */
            $cache = new ReflectionClass(SQLFormatter::class)->getProperty("tokenCache")->getValue();
            return $cache;
        } catch (ReflectionException $exception) {
            self::fail($exception->getMessage());
        }
    }

    private static function formatted(string $sql, int $style): string
    {
        return (string)new SQLFormatter($style)->string($sql);
    }

    private static function plain(string $string): string
    {
        return (string)preg_replace("/\e\[[\d;]*m/", "", $string);
    }

    /**
     * Decoding the color is the only way the tokenizer's classification is visible from
     * outside the class — token() and tokens() are both private.
     *
     * @return array<int, array{string, string}>
     */
    private static function tokenTypes(string $sql): array
    {
        $output = self::formatted($sql, SQLFormatterStyle::highlighted);
        preg_match_all("/\e\[(\d);(\d+)m(.*?)\e\[0m/s", $output, $matches, PREG_SET_ORDER);
        $tokens = [];
        /** @var array{0: string, 1: string, 2: string, 3: string} $match */
        foreach ($matches as $match) {
            if (trim($match[3]) === "") {
                continue;
            }
            $name = self::ColorNames[(int)$match[2]] ?? $match[2];
            if ($match[1] === "3") {
                $name = "function";
            }
            $tokens[] = [$match[3], $name];
        }
        return $tokens;
    }

    private static function tokenTypeOf(string $sql, string $text): ?string
    {
        $match = array_find(self::tokenTypes($sql), fn(array $token): bool => $token[0] === $text);
        return $match[1] ?? null;
    }

    /**
     * string() is a Formatter override and must decline anything that is not a string rather
     * than coercing it — the caller distinguishes "not mine" from a formatted result.
     */
    public function testNonStringInputIsDeclined(): void
    {
        $formatter = new SQLFormatter();
        $this->assertNull($formatter->string(42));
        $this->assertNull($formatter->string(null));
        $this->assertNull($formatter->string(new SQLStatement("SELECT 1")));
    }

    public function testDefaultStyleIsHighlighted(): void
    {
        $this->assertSame(SQLFormatterStyle::highlighted, new SQLFormatter()->style);
    }

    /**
     * The neutral style is a genuine passthrough: no reflow, no color, not even a trailing
     * newline. Anything else would corrupt a statement being logged verbatim.
     */
    public function testNoneStyleReturnsTheInputUnchanged(): void
    {
        $sql = "SELECT id FROM person WHERE age > 30";
        $this->assertSame($sql, self::formatted($sql, SQLFormatterStyle::none));
    }

    /**
     * Highlighting colors the statement but must not move any character of it. The trailing
     * newline is the one addition, and it is what separates consecutive logged statements.
     */
    public function testHighlightingPreservesTheTextAndAppendsANewline(): void
    {
        $sql = "SELECT id FROM person WHERE age > 30";
        $output = self::formatted($sql, SQLFormatterStyle::highlighted);

        $this->assertNotSame($sql, $output, "the highlighted style must emit escape sequences");
        $this->assertStringContainsString("\e[", $output);
        $this->assertSame("$sql\n", self::plain($output));
    }

    /**
     * Pretty-printing puts each top-level clause on its own line and indents what belongs to
     * it. Asserting the whole block rather than a substring is deliberate: the layout *is* the
     * behavior, so a change in spacing or line breaks should fail here.
     */
    public function testPrettyPrintingBreaksClausesOntoTheirOwnLines(): void
    {
        $sql = "SELECT a.id, a.name FROM person a LEFT JOIN address b ON a.id = b.person_id WHERE a.age > 30 ORDER BY a.name";

        $expected = implode("\n", [
            "SELECT ",
            "    a.id, ",
            "    a.name ",
            "FROM ",
            "    person a ",
            "    LEFT JOIN address b ON a.id = b.person_id ",
            "WHERE ",
            "    a.age > 30 ",
            "ORDER BY ",
            "    a.name",
        ]);

        $this->assertSame($expected, self::formatted($sql, SQLFormatterStyle::prettyPrinted));
    }

    /**
     * LIMIT is the one clause whose comma does not break the line: "LIMIT 10, 5" is a single
     * offset/row-count pair and splitting it reads as two clauses. That is the clauseLimit
     * branch in format().
     */
    public function testLimitKeepsItsOffsetAndCountOnOneLine(): void
    {
        $output = self::formatted("SELECT a FROM t LIMIT 10, 5", SQLFormatterStyle::prettyPrinted);

        $this->assertStringContainsString("LIMIT \n    10, 5", $output);
    }

    /**
     * A short parenthesised group stays inline; only a long one is broken out and indented.
     * Both halves are asserted together because the threshold is the point of the branch.
     */
    public function testShortParenthesesStayInlineAndLongOnesAreBrokenOut(): void
    {
        $short = self::formatted("SELECT a FROM t WHERE id IN (1, 2, 3)", SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString("id IN (1, 2, 3)", $short);

        $long = self::formatted("SELECT a FROM t WHERE id IN (1111111,2222222,3333333,4444444,5555555,6666666)", SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString("id IN (\n", $long);
        $this->assertStringContainsString("\n        1111111, ", $long);
    }

    /**
     * Nested parentheses each open their own block, and a long inline list closes on a line of
     * its own rather than trailing after the last element.
     */
    public function testNestedAndClosingParenthesesGetTheirOwnLines(): void
    {
        $nested = self::formatted("SELECT a FROM t WHERE ((b))", SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString("(\n        (b)\n    )", $nested);

        $long = self::formatted("SELECT a FROM t WHERE id IN (111111111,222222222,333333333,444444444,55555,66666,77777,88888,99999)", SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString("99999\n    )", $long);
    }

    /**
     * A subquery indents one level deeper than the clause holding it.
     */
    public function testSubqueryIsIndentedInsideItsParent(): void
    {
        $sql = "SELECT a FROM t WHERE id IN (SELECT person_id FROM address WHERE city = 1)";
        $output = self::formatted($sql, SQLFormatterStyle::prettyPrinted);

        $this->assertStringContainsString("IN (\n        SELECT \n            person_id \n", $output);
    }

    /**
     * Unbalanced parentheses are a bug in the caller, not in the formatter, so the formatter
     * still prints the statement — but under the highlighted style it appends a warning so the
     * truncation is visible in the log instead of being mistaken for the whole query.
     */
    public function testUnclosedParenthesisWarnsWhenHighlighting(): void
    {
        $sql = /** @lang text */ "SELECT a FROM (SELECT b FROM inner_table WHERE c = 1";

        $highlighted = self::formatted($sql, SQLFormatterStyle::highlighted | SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString("WARNING: unclosed parentheses or section", self::plain($highlighted));

        $plain = self::formatted($sql, SQLFormatterStyle::prettyPrinted);
        $this->assertStringNotContainsString("WARNING", $plain, "the warning is a highlighting affordance, not part of the SQL");
    }

    /**
     * The two style bits are independent, so all four combinations must stay distinguishable.
     */
    public function testTheFourStyleCombinationsAreDistinct(): void
    {
        $sql = "SELECT id FROM t WHERE a = 1";

        $outputs = [
            "none" => self::formatted($sql, SQLFormatterStyle::none),
            "highlighted" => self::formatted($sql, SQLFormatterStyle::highlighted),
            "prettyPrinted" => self::formatted($sql, SQLFormatterStyle::prettyPrinted),
            "both" => self::formatted($sql, SQLFormatterStyle::highlighted | SQLFormatterStyle::prettyPrinted),
        ];

        $this->assertSame($outputs, array_unique($outputs), "each style combination must produce a different rendering");

        $this->assertStringNotContainsString("\e[", $outputs["none"]);
        $this->assertStringNotContainsString("\n", $outputs["none"]);
        $this->assertStringContainsString("\e[", $outputs["highlighted"]);
        $this->assertStringNotContainsString("\n    ", $outputs["highlighted"]);
        $this->assertStringNotContainsString("\e[", $outputs["prettyPrinted"]);
        $this->assertStringContainsString("\n    ", $outputs["prettyPrinted"]);
        $this->assertStringContainsString("\e[", $outputs["both"]);
        $this->assertStringContainsString("\n    ", $outputs["both"]);
    }

    /**
     * One case per token type the tokenizer can produce, asserted through the color the
     * highlighter paints it with. These are the classifications the pretty-printer's layout
     * decisions are built on, so a tokenizer regression surfaces here first.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function tokenClassifications(): array
    {
        return [
            "top-level keyword" => ["SELECT a FROM t", "SELECT", "reserved"],
            "newline keyword" => ["SELECT a FROM t INNER JOIN u ON 1", "INNER JOIN", "reserved"],
            "plain keyword" => ["SELECT a FROM t WHERE b IS NULL", "NULL", "reserved"],
            "keyword is case insensitive" => ["select a from t", "select", "reserved"],
            "bare word" => ["SELECT alpha FROM t", "alpha", "whitespace|word|backtickQuote"],
            "single-quoted string" => ["SELECT 'text' FROM t", "'text'", "quote"],
            "double-quoted string" => ["SELECT \"text\" FROM t", "\"text\"", "quote"],
            "backtick identifier" => ["SELECT `col name` FROM t", "`col name`", "whitespace|word|backtickQuote"],
            "bracket identifier" => [/** @lang text */ "SELECT [col] FROM t", "[col]", "whitespace|word|backtickQuote"],
            "integer" => ["SELECT 7 FROM t", "7", "number"],
            "decimal" => ["SELECT 3.14 FROM t", "3.14", "number"],
            "hexadecimal" => ["SELECT 0xFF FROM t", "0xFF", "number"],
            "binary" => ["SELECT 0b1010 FROM t", "0b1010", "number"],
            "boundary" => ["SELECT a = 1 FROM t", "=", "boundary|function"],
            "function" => ["SELECT COUNT(x) FROM t", "COUNT", "function"],
            "column after a dot" => ["SELECT tbl.colname FROM tbl", "colname", "column"],
            "at-variable" => ["SELECT @foo FROM t", "@foo", "comment|variable"],
            "colon-variable" => ["SELECT :named FROM t", ":named", "comment|variable"],
            "quoted variable" => ["SELECT @\"q v\" FROM t", "@\"q v\"", "comment|variable"],
            "line comment" => ["SELECT 1 -- tail", "-- tail", "comment|variable"],
            "hash comment" => ["SELECT 1 # tail", "# tail", "comment|variable"],
            "block comment" => ["SELECT /* note */ 1", "/* note */", "comment|variable"],
            "unterminated block comment" => ["SELECT /* never closed", "/* never closed", "comment|variable"],
        ];
    }

    #[DataProvider("tokenClassifications")]
    public function testTokenIsClassified(string $sql, string $text, string $expected): void
    {
        $this->assertSame($expected, self::tokenTypeOf($sql, $text));
    }

    /**
     * A word only becomes a column when a dot precedes it; the table name in front of the dot
     * stays a plain word. This is the `previous->value === "."` branch in token(), and it is
     * also what stops a reserved word after a dot from being read as a keyword.
     */
    public function testDotDecidesWhatIsAColumn(): void
    {
        $this->assertSame("whitespace|word|backtickQuote", self::tokenTypeOf("SELECT tbl.colname FROM tbl", "tbl"));
        $this->assertSame("column", self::tokenTypeOf("SELECT tbl.colname FROM tbl", "colname"));
        $this->assertSame("column", self::tokenTypeOf("SELECT t.from FROM t", "from"));
    }

    /**
     * Comments are preserved verbatim, including the newline that ends a line comment — a
     * comment that swallowed its terminator would comment out the rest of the statement.
     */
    public function testCommentsSurviveHighlightingIntact(): void
    {
        $sql = "SELECT 1 -- trailing\nFROM t";
        $this->assertSame("$sql\n", self::plain(self::formatted($sql, SQLFormatterStyle::highlighted)));
    }

    /**
     * A block comment is moved onto its own line when pretty-printing, and its continuation
     * lines are re-indented to the nesting level it sits at — otherwise the second line of a
     * multi-line comment would hang at column zero and break the shape of the block.
     */
    public function testBlockCommentIsIndentedToItsNestingLevel(): void
    {
        $single = self::formatted("SELECT /* nota */ a FROM t", SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString("SELECT \n    \n    /* nota */\n    a ", $single);

        $multiline = self::formatted("SELECT a FROM t WHERE /* linea1\nlinea2 */ b = 1", SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString("\n    /* linea1\n    linea2 */\n    b = 1", $multiline);
    }

    /**
     * A line comment, unlike a block comment, stays on the line it annotates.
     */
    public function testLineCommentStaysOnItsLine(): void
    {
        $output = self::formatted("SELECT a -- nota\nFROM t", SQLFormatterStyle::prettyPrinted);

        $this->assertStringContainsString("    a -- nota\nFROM ", $output);
    }

    /**
     * A minus sign directly before a number is a sign, not a subtraction, when what precedes
     * it is an operator rather than a value — so it binds to the number instead of taking a
     * space. This is the lookahead at the end of format().
     */
    public function testUnaryMinusBindsToItsNumber(): void
    {
        $this->assertStringContainsString("b > -5", self::formatted("SELECT a FROM t WHERE b > -5", SQLFormatterStyle::prettyPrinted));
        $this->assertStringContainsString("b - 5", self::formatted(/** @lang text */ "SELECT a FROM t WHERE b - 5",SQLFormatterStyle::prettyPrinted));
    }

    /**
     * A closing parenthesis with nothing open is malformed input, and the formatter must not
     * let the indent level go negative and start subtracting from later clauses; it clamps and
     * keeps printing.
     */
    public function testUnbalancedClosingParenthesisIsClamped(): void
    {
        $sql = /** @lang text */ "SELECT a FROM t)";

        $this->assertSame("SELECT \n    a \nFROM \n    t\n)", self::formatted($sql, SQLFormatterStyle::prettyPrinted));

        $highlighted = self::formatted($sql, SQLFormatterStyle::highlighted | SQLFormatterStyle::prettyPrinted);
        $this->assertStringContainsString(")", self::plain($highlighted));
    }

    /**
     * A statement with no recognizable structure still round-trips through the tokenizer
     * rather than being dropped or looping.
     */
    public function testUnstructuredInputStillRoundTrips(): void
    {
        foreach (["", " ", "~", "?", "\\", "€"] as $input) {
            $this->assertSame($input, self::formatted($input, SQLFormatterStyle::none));
        }

        $this->assertSame("~\n", self::plain(self::formatted("~", SQLFormatterStyle::highlighted)));
    }

    /**
     * The indent string is a knob, and the pretty-printer must honour it rather than the tab
     * it uses internally as a placeholder. A leaked tab would mean the substitution was missed.
     */
    public function testIndentIsConfigurable(): void
    {
        SQLFormatter::$indent = "..";
        $output = self::formatted("SELECT a FROM b", SQLFormatterStyle::prettyPrinted);

        $this->assertStringContainsString("\n..a", $output);
        $this->assertStringNotContainsString("\t", $output);
    }

    /**
     * The tokenizer memoizes by 15-character prefix. The cache is an optimization, so the only
     * thing that must hold is that it changes nothing: a warm run and a cold run agree.
     */
    public function testTokenCacheDoesNotChangeTheResult(): void
    {
        $sql = "SELECT identifier FROM some_table WHERE identifier = 1 AND other = 2";

        $cold = self::formatted($sql, SQLFormatterStyle::prettyPrinted);
        $this->assertNotEmpty(self::tokenCache(), "a statement longer than the key width should populate the cache");

        $warm = self::formatted($sql, SQLFormatterStyle::prettyPrinted);
        $this->assertSame($cold, $warm);

        self::clearTokenCache();
        $this->assertSame($cold, self::formatted($sql, SQLFormatterStyle::prettyPrinted));
    }

    /**
     * The cache is bounded by emptying it wholesale once it reaches the entry limit, so a
     * long-lived process formatting many distinct statements cannot grow it without bound.
     */
    public function testTokenCacheIsBounded(): void
    {
        SQLFormatter::$maxCacheEntries = 5;

        for ($i = 0; $i < 40; $i++) {
            self::formatted("SELECT column_number_$i FROM table_number_$i WHERE x = $i", SQLFormatterStyle::prettyPrinted);
        }

        /** @noinspection PhpPipeOperatorCanBeUsedInspection */
        $this->assertLessThanOrEqual(5, count(self::tokenCache()));
    }

    /**
     * The style flags reach SQLFormatter through SQLStatementFormatter, which is how the store
     * actually renders a statement. Covering the seam keeps the two flag vocabularies — the
     * statement-level style and the formatter-level one — from drifting apart.
     */
    public function testStatementFormatterMapsItsStyleOntoTheFormatter(): void
    {
        $statement = new SQLStatement("SELECT id FROM person WHERE age > ?", new ArrayClass([30]));

        $raw = new SQLStatementFormatter(SQLStatementFormatterStyle::none)->string($statement);
        $this->assertSame("SELECT id FROM person WHERE age > ?", $raw);

        $withArguments = new SQLStatementFormatter(SQLStatementFormatterStyle::includeArguments)->string($statement);
        $this->assertSame("SELECT id FROM person WHERE age > 30", $withArguments);

        $pretty = (string)new SQLStatementFormatter(SQLStatementFormatterStyle::includeArguments | SQLStatementFormatterStyle::prettyPrint)->string($statement);
        $this->assertStringContainsString("WHERE \n    age > 30", $pretty);

        $highlighted = (string)new SQLStatementFormatter(SQLStatementFormatterStyle::includeArguments | SQLStatementFormatterStyle::highlight)->string($statement);
        $this->assertStringContainsString("\e[", $highlighted);
        $this->assertSame("SELECT id FROM person WHERE age > 30\n", self::plain($highlighted));
    }

    /**
     * A formatter is asked to render only what it recognizes; SQLStatementFormatter takes
     * SQLStatement, not the raw string SQLFormatter takes.
     */
    public function testStatementFormatterDeclinesARawString(): void
    {
        $this->assertNull(new SQLStatementFormatter()->string("SELECT 1"));
    }
}
