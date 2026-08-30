<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\ExpectedValues;

/** @internal */
final class SQLStatementFormatterStyle
{
    const int none = 0;
    const int interpolateStrings = 1;
    const int includeArguments = 2;
    const int prettyPrint = 4;
    const int highlight = 8;

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
