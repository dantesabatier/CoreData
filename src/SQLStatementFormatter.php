<?php

namespace Sabatier\CoreData;

use BackedEnum;
use JetBrains\PhpStorm\ExpectedValues;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Formatter;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use function Sabatier\Foundation\human_readable_value;

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
                $string = sprintf(str_replace(['%', '?'], ['%%', '%s'], $string), ...$object->arguments->map(function (mixed $e): string {
                    if ($e instanceof Number || $e instanceof Nil || $e instanceof BackedEnum) {
                        return human_readable_value($e->value);
                    } elseif ($e instanceof ManagedObjectID) {
                        return (string)$e->referenceObject;
                    } elseif (is_string($e) || $e instanceof Date || $e instanceof UUID || $e instanceof URL) {
                        return "'$e'";
                    } else {
                        return human_readable_value($e);
                    }
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