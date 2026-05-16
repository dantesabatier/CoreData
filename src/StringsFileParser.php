<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final readonly class StringsFileParser
{
    /** @var Dictionary<string> */
    public Dictionary $dictionary;

    public function __construct(private string $content)
    {
        $msgid = null;
        /** @var Dictionary<string> $dictionary */
        $dictionary = new Dictionary();
        foreach (explode("\n", $this->content) as $line) {
            $line = trim($line);
            if (str_starts_with($line, "msgid \"")) {
                $msgid = substr($line, 7, -1);
            } elseif (str_starts_with($line, "msgstr \"") && $msgid !== null && $msgid !== "") {
                $msgstr = substr($line, 8, -1);
                if ($msgstr !== "") {
                    $dictionary[$msgid] = $msgstr;
                }
                $msgid = null;
            } elseif ($line === "" || str_starts_with($line, "#")) {
                $msgid = null;
            }
        }
        $this->dictionary = $dictionary;
    }
}
