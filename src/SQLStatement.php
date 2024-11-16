<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/06/20
 * Time: 21:56
 */

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\ExpectedValues;
use JetBrains\PhpStorm\Language;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\CompareOptions;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\SearchMethod;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\string_is_equal;
use function Sabatier\Foundation\string_search;

/** @internal */
class SQLStatement extends ObjectClass
{
    public string $description {
        get => $this->formatted();
    }

    /**
     * @param string $string
     * @param ArrayClass $arguments
     */
    public function __construct(#[Language("SQL")] public readonly string $string, public readonly ArrayClass $arguments = new ArrayClass())
    {
        if (str_ends_with($this->string, ";")) {
            fatal_error("Invalid sql statement: sql string must not end with a semicolon \";\"");
        }
        $numberOfArguments = $this->arguments->count;
        $numberOfPlaceholders = string_search($this->string, "?", SearchMethod::contains);
        if ($numberOfArguments !== $numberOfPlaceholders) {
            fatal_error(sprintf("Invalid sql statement: number of arguments (%s) does not match the number of placeholders (%s)\n\"%s\"\n%s", $numberOfArguments, $numberOfPlaceholders, $this->string, human_readable_value($this->arguments)));
        }
    }

    /**
     * @param ArrayClass<SQLStatement> $statements
     * @return SQLStatement
     */
    public static function merging(ArrayClass $statements): SQLStatement
    {
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        return match ($statements->count) {
            0 => fatal_error("Invalid sql statement: statements cannot be empty"),
            1 => $statements[0],
            default => new SQLStatement($statements->map(fn(SQLStatement $statement): string => $statement->string)->join(";\n"), $statements->flatMap(fn(SQLStatement $statement): ArrayClass => $statement->arguments))
        };
    }

    public function formatted(#[ExpectedValues(flagsFromClass: SQLStatementFormatterStyle::class)] int $style = SQLStatementFormatterStyle::string | SQLStatementFormatterStyle::arguments): string
    {
        return new SQLStatementFormatter($style)->string($this) ?? $this->string;
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLStatement) {
            return string_is_equal((string)$this, (string)$other, CompareOptions::caseInsensitive | CompareOptions::diacriticInsensitive);
        }
        return false;
    }
}
