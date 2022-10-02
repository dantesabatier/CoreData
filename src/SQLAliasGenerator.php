<?php

namespace Sabatier\CoreData;

/** @internal */
class SQLAliasGenerator
{
    private int $nextTableAlias = 0;
    private int $nextVariableAlias = 0;
    public string $tableBase;
    public string $variableBase;

    public function generateVariableAlias(): string
    {
        $alias = "$this->variableBase.$this->nextVariableAlias";
        $this->nextVariableAlias += 1;
        return $alias;
    }

    public function generateTableAlias(): string
    {
        $alias = "$this->tableBase.$this->nextTableAlias";
        $this->nextTableAlias += 1;
        return $alias;
    }
}
