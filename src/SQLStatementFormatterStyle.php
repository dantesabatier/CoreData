<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\ExpectedValues;

/** @internal */
final class SQLStatementFormatterStyle
{
    final const int none = 0;
    final const int interpolateStrings = 1;
    final const int includeArguments = 2;
    final const int prettyPrint = 4;
    final const int highlight = 8;

    #[ExpectedValues(flagsFromClass: SQLStatementFormatterStyle::class)]
    public static function defaultFormatterStyle(): int
    {
        $style = SQLStatementFormatterStyle::interpolateStrings;
        $level = SQLCore::$debugLevel->value;
        if ($level > SQLDebugLevel::rawSQL->value) {
            $style |= SQLStatementFormatterStyle::includeArguments;
        }
        if ($level > SQLDebugLevel::sqlWithParams->value) {
            $style |= SQLStatementFormatterStyle::prettyPrint;
        }
        if (SQLCore::$debugColorOutputDefault) {
            $style |= SQLStatementFormatterStyle::highlight;
        }
        return $style;
    }
}
