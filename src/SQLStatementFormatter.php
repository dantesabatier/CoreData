<?php

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\ExpectedValues;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Formatter;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\typeof;

/** @internal */
class SQLStatementFormatter extends Formatter
{
    public function __construct(#[ExpectedValues(flagsFromClass: SQLStatementFormatterStyle::class)] public int $style = SQLStatementFormatterStyle::string)
    {
    }

    public function string(mixed $object): ?string
    {
        if ($object instanceof SQLStatement) {
            $string = $object->string;
            if ($this->style & SQLStatementFormatterStyle::arguments) {
                $string = sprintf(str_replace(["%", "?"], ["%%", "%s"], $string), ...$object->arguments->map(fn(mixed $e): string => match (typeof($e)) {
                    ManagedObjectID::class => (string)$e->referenceObject,
                    Date::class, UUID::class, URL::class, "string" => "'$e'",
                    default => (function () use ($e): string {
                        if ($e instanceof Value) {
                            $e = $e->value;
                        }
                        $v = human_readable_value($e);
                        if (is_bool($e) || is_null($e)) {
                            $v = strtoupper($v);
                        }
                        return $v;
                    })()
                })->toArray());
            }
            $style = 0;
            if ($this->style & SQLStatementFormatterStyle::highlighted) {
                $style |= SQLFormatterStyle::highlighted;
            }
            if ($this->style & SQLStatementFormatterStyle::prettyPrint) {
                $style |= SQLFormatterStyle::prettyPrint;
            }
            $formatter = new SQLFormatter($style);
            return $formatter->string($string);
        }
        return null;
    }
}
