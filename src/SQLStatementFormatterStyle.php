<?php

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\ExpectedValues;

/** @internal */
class SQLStatementFormatterStyle
{
    final const int none = 0;
    final const int string = 1;
    final const int arguments = 2;
    final const int prettyPrint = 4;
    final const int highlighted = 8;

    #[ExpectedValues(flagsFromClass: SQLStatementFormatterStyle::class)]
    public static function defaultFormatterStyle(): int
    {
        $style = SQLStatementFormatterStyle::string;
        if (SQLCore::$debugDefault > 1) {
            $style |= SQLStatementFormatterStyle::arguments;
        }
        if (SQLCore::$debugDefault > 2) {
            $style |= SQLStatementFormatterStyle::prettyPrint;
        }
        if (SQLCore::$coloredLoggingDefault) {
            $style |= SQLStatementFormatterStyle::highlighted;
        }
        return $style;
    }
}
