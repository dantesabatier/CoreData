<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

/** @internal */
final class SQLAliasGenerator
{
    private int $nextTableAlias = 0;
    private int $nextVariableAlias = 0;
    private int $nextTempTableAlias = 0;

    public string $tableBase;
    public string $variableBase;

    public function __construct(public readonly int $nestingLevel = 1)
    {
        $this->tableBase = "t{$nestingLevel}_";
        $this->variableBase = "v{$nestingLevel}_";
    }

    public function generateTempTableName(): string
    {
        return "tmp{$this->nestingLevel}_" . ($this->nextTempTableAlias++);
    }

    public function generateTableAlias(): string
    {
        return $this->tableBase . ($this->nextTableAlias++);
    }

    public function generateSubqueryVariableAlias(): string
    {
        return "sub{$this->nestingLevel}_" . ($this->nextVariableAlias++);
    }

    public function generateVariableAlias(): string
    {
        return $this->variableBase . ($this->nextVariableAlias++);
    }
}
