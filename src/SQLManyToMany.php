<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 10:43
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonResult;
use Sabatier\Foundation\SortDescriptor;

/** @internal */
class SQLManyToMany extends SQLRelationship
{
    public SQLManyToMany $inverseManyToMany {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        get => $this->inverseRelationship;
    }
    public string $correlationTableName {
        get => $this->correlationTableName ??= new ArrayClass([$this->destinationEntity, $this->inverseRelationship->destinationEntity])->sorted([new SortDescriptor("tableName")])->valueForKey("tableName")->join("");
    }
    public string $columnName {
        get => $this->columnName ??= "{$this->name}ID";
    }
    public SQLType $columnSQLType {
        get => SQLType::int;
    }
    public string $orderColumnName {
        get => $this->orderColumnName ??= $this->isReflexive ? $this->columnName : new ArrayClass([$this->columnName, $this->inverseColumnName])->sort(fn(string $e, string $e1): int => ComparisonResult::orderedAscending->value * ($e <=> $e1))[0];
    }
    private(set) SQLType $orderColumnSQLType = SQLType::int;
    public string $inverseColumnName {
        get => $this->inverseManyToMany->columnName;
    }
    public string $inverseOrderColumnName {
        get => $this->inverseOrderColumnName ??= $this->isReflexive ? $this->columnName : new ArrayClass([$this->columnName, $this->inverseColumnName])->sort(fn(string $e, string $e1): int => ComparisonResult::orderedAscending->value * ($e <=> $e1))[1];
    }
    public bool $isReflexive {
        get => $this->isReflexive ??= $this->columnName === $this->inverseColumnName;
    }
    public bool $isMaster {
        get => false;
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLManyToMany) {
            return $this->correlationTableName === $other->correlationTableName;
        }
        return parent::isEqual($other);
    }
}
