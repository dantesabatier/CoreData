<?php

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\ExpectedValues;
use Override;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Formatter;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\typeof;

/** @internal */
final class SQLStatementFormatter extends Formatter
{
    public function __construct(#[ExpectedValues(flagsFromClass: SQLStatementFormatterStyle::class)] public int $style = SQLStatementFormatterStyle::interpolateStrings)
    {
    }

    #[Override]
    public function string(mixed $object): ?string
    {
        if (!$object instanceof SQLStatement) {
            return null;
        }
        $string = $object->string;
        if ($this->style & SQLStatementFormatterStyle::includeArguments) {
            $string = sprintf(str_replace(["%", "?"], ["%%", "%s"], $string), ...$object->arguments->map(fn(mixed $e): string => match (typeof($e)) {
                ManagedObjectID::class => (string)$e->referenceObject,
                Date::class, UUID::class, URL::class => "'$e'",
                "string" => mb_check_encoding($e, "UTF-8") ? "'" . addslashes((string)$e) . "'" : "(binary data)",
                default => (function () use ($e): string {
                    if ($e instanceof Value) {
                        $e = $e->value;
                    }
                    $v = human_readable_value($e);
                    if (is_bool($e) || is_null($e)) {
                        return strtoupper($v);
                    }
                    return $v;
                })()
            })->array);
        }
        $style = SQLFormatterStyle::none;
        if ($this->style & SQLStatementFormatterStyle::highlight) {
            $style |= SQLFormatterStyle::highlighted;
        }
        if ($this->style & SQLStatementFormatterStyle::prettyPrint) {
            $style |= SQLFormatterStyle::prettyPrint;
        }
        $formatter = new SQLFormatter($style);
        return $formatter->string($string);
    }
}
