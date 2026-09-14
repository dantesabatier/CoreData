<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\StringsFileParser;

/**
 * Covers StringsFileParser, which reads the gettext .po catalogues under Resources/ that supply
 * the localized validation error messages.
 *
 * The parser is a small state machine over the lines of a catalogue: it remembers the last msgid
 * it saw and pairs it with the next msgstr. What makes it worth pinning is everything it
 * deliberately refuses to pair — an empty msgid (the header entry every .po file opens with), an
 * empty msgstr (an untranslated string), and a msgid separated from its msgstr by a blank line or
 * a comment, which in gettext means the entry was abandoned.
 *
 * Getting any of those wrong is quiet: the catalogue still parses, and a wrong or missing
 * translation only surfaces to whoever reads the message.
 */
final class StringsFileParserTest extends TestCase
{
    public function testPairsAMsgidWithItsMsgstr(): void
    {
        $parser = new StringsFileParser("msgid \"greeting\"\nmsgstr \"hola\"");

        $this->assertSame("hola", $parser->dictionary["greeting"]);
    }

    public function testReadsSeveralEntries(): void
    {
        $parser = new StringsFileParser("msgid \"one\"\nmsgstr \"uno\"\n\nmsgid \"two\"\nmsgstr \"dos\"");

        $this->assertSame("uno", $parser->dictionary["one"]);
        $this->assertSame("dos", $parser->dictionary["two"]);
        $this->assertSame(2, $parser->dictionary->count);
    }

    /**
     * Every .po file opens with a header entry whose msgid is empty and whose msgstr holds the
     * catalogue metadata. Admitting it would put the metadata blob in the catalogue under an
     * empty key.
     */
    public function testSkipsTheEmptyHeaderEntry(): void
    {
        $parser = new StringsFileParser("msgid \"\"\nmsgstr \"Content-Type: text/plain\"\n\nmsgid \"real\"\nmsgstr \"real translation\"");

        $this->assertNull($parser->dictionary[""], "the header msgid is not a translation");
        $this->assertSame(1, $parser->dictionary->count, "only the real entry is kept");
    }

    /**
     * An untranslated string has an empty msgstr. Admitting it would map the key to "" and the
     * lookup would return an empty message rather than falling back to the untranslated key.
     */
    public function testSkipsAnUntranslatedEntry(): void
    {
        $parser = new StringsFileParser("msgid \"untranslated\"\nmsgstr \"\"");

        $this->assertTrue($parser->dictionary->isEmpty);
    }

    /**
     * A blank line ends the entry: gettext treats the msgid as abandoned, so a msgstr that
     * follows it belongs to no key.
     */
    public function testABlankLineAbandonsThePendingMsgid(): void
    {
        $parser = new StringsFileParser("msgid \"orphan\"\n\nmsgstr \"stranded\"");

        $this->assertTrue($parser->dictionary->isEmpty, "a msgstr separated by a blank line pairs with nothing");
    }

    /**
     * A comment ends the entry the same way a blank line does.
     */
    public function testACommentAbandonsThePendingMsgid(): void
    {
        $parser = new StringsFileParser("msgid \"orphan\"\n# translator note\nmsgstr \"stranded\"");

        $this->assertTrue($parser->dictionary->isEmpty);
    }

    /**
     * A msgstr with no msgid at all pairs with nothing rather than raising.
     */
    public function testAMsgstrWithNoMsgidIsIgnored(): void
    {
        $parser = new StringsFileParser("msgstr \"nowhere\"");

        $this->assertTrue($parser->dictionary->isEmpty);
    }

    public function testEmptyContentYieldsAnEmptyCatalogue(): void
    {
        $this->assertTrue(new StringsFileParser("")->dictionary->isEmpty);
    }

    /**
     * Lines are trimmed before they are matched, so an indented catalogue parses the same as a
     * flush-left one.
     */
    public function testLeadingWhitespaceDoesNotPreventAMatch(): void
    {
        $parser = new StringsFileParser("   msgid \"indented\"\n   msgstr \"sangrado\"");

        $this->assertSame("sangrado", $parser->dictionary["indented"]);
    }

    /**
     * A second msgstr after a completed pair has no pending msgid to attach to — the parser
     * clears the key as soon as it stores the pair, so a repeated line cannot overwrite it.
     */
    public function testARepeatedMsgstrDoesNotOverwriteTheStoredPair(): void
    {
        $parser = new StringsFileParser("msgid \"key\"\nmsgstr \"first\"\nmsgstr \"second\"");

        $this->assertSame("first", $parser->dictionary["key"]);
        $this->assertSame(1, $parser->dictionary->count);
    }

    /**
     * The last msgid wins when two are seen before any msgstr, which is what lets the parser
     * recover from a malformed entry rather than pairing the translation with a stale key.
     */
    public function testTheMostRecentMsgidWinsWhenNoMsgstrIntervenes(): void
    {
        $parser = new StringsFileParser("msgid \"stale\"\nmsgid \"current\"\nmsgstr \"value\"");

        $this->assertSame("value", $parser->dictionary["current"]);
        $this->assertNull($parser->dictionary["stale"]);
    }
}
