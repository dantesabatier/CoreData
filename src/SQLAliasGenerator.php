<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
class SQLAliasGenerator
{
    private int $nextTableAlias = 0;
    private int $nextVariableAlias = 0;
    public string $tableBase;
    public string $variableBase;
    /** @var Dictionary<string> */

    private Dictionary $byBaseAssociationTable;

    public function __construct(public readonly int $nestingLevel = 1)
    {
        $this->byBaseAssociationTable = new Dictionary();
    }

    public function generateTableAlias(): string
    {
        $this->nextTableAlias = max($this->nestingLevel, $this->nextTableAlias);
        if (!($alias = $this->byBaseAssociationTable[$this->tableBase])) {
            $alias = "t$this->nextTableAlias";
            $this->nextTableAlias += 1;
            $this->byBaseAssociationTable[$this->tableBase] = $alias;
        }
        return $alias;
    }

    public function generateSubqueryVariableAlias(): string
    {
        $this->nextVariableAlias = max($this->nestingLevel, $this->nextVariableAlias);
        if (!($alias = $this->byBaseAssociationTable[$this->variableBase])) {
            $alias = "{$this->generateTableAlias()}.$this->variableBase.$this->nextVariableAlias";
            $this->nextVariableAlias += 1;
            $this->byBaseAssociationTable[$this->variableBase] = $alias;
        }
        return $alias;
    }
}
