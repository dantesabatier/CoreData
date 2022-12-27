<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/06/20
 * Time: 21:56
 */

namespace Sabatier\CoreData;

use InvalidArgumentException;
use JetBrains\PhpStorm\ExpectedValues;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\CompareOptions;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\SearchMethod;
use function Sabatier\Foundation\string_ends_with;
use function Sabatier\Foundation\string_is_equal;
use function Sabatier\Foundation\string_search;

/** @internal */
class SQLStatement extends ObjectClass
{
    /**
     * @param string $string
     * @param ArrayClass<mixed> $arguments
     */
    public function __construct(public readonly string $string, public readonly ArrayClass $arguments = new ArrayClass())
    {
        if (string_ends_with($this->string, ";")) {
            throw new InvalidArgumentException("Invalid sql statement: sql string must not end with a semicolon \";\"");
        }
        $numberOfArguments = $this->arguments->count();
        $numberOfPlaceholders = string_search($this->string, "?", SearchMethod::contains);
        if ($numberOfArguments !== $numberOfPlaceholders) {
            throw new InvalidArgumentException(sprintf("Invalid sql statement: number of arguments (%s) does not match the number of placeholders (%s)\n\"%s\"\n%s", $numberOfArguments, $numberOfPlaceholders, $this->string, $this->arguments->description()));
        }
    }

    /**
     * @param ArrayClass<SQLStatement> $statements
     * @return SQLStatement
     */
    public static function merging(ArrayClass $statements): SQLStatement
    {
        if ($statements->isEmpty()) {
            throw new InvalidArgumentException("Invalid sql statement: statements cannot be empty");
        }
        if ($statements->count() === 1) {
            return $statements[0];
        }
        return new SQLStatement($statements->map(fn(SQLStatement $statement): string => $statement->string)->join(";\n"), $statements->flatMap(fn(SQLStatement $statement): ArrayClass => $statement->arguments));
    }

    public function formatted(#[ExpectedValues(flagsFromClass: SQLStatementFormatterStyle::class)] int $style = SQLStatementFormatterStyle::string | SQLStatementFormatterStyle::arguments): string
    {
        $formatter = new SQLStatementFormatter($style);
        return $formatter->string($this) ?? $this->string;
    }

    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLStatement) {
            return string_is_equal((string)$this, (string)$other, CompareOptions::caseInsensitive | CompareOptions::diacriticInsensitive);
        }
        return false;
    }

    public function description(): string
    {
        return $this->formatted();
    }
}
