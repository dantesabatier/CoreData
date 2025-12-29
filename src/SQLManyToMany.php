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
final class SQLManyToMany extends SQLRelationship
{
    public SQLManyToMany $inverseManyToMany {
        get {
            /** @var SQLManyToMany $inverseManyToMany */
            $inverseManyToMany = $this->inverseRelationship;
            return $inverseManyToMany;
        }
    }
    /** @var ArrayClass<SQLEntity> */
    private(set) ArrayClass $entities {
        get => $this->entities ??= new ArrayClass([$this->destinationEntity, $this->inverseRelationship->destinationEntity])->sorted([new SortDescriptor("tableName")]);
    }
    private(set) string $correlationTableName {
        get => $this->correlationTableName ??= $this->entities->map(fn(SQLEntity $entity): string => $entity->tableName)->join("");
    }
    private(set) string $columnName {
        get => $this->columnName ??= "{$this->name}ID";
    }
    public SQLType $columnSQLType {
        get => SQLType::int;
    }
    /** @var ArrayClass<string> */
    private(set) ArrayClass $columnNames {
        get => $this->columnNames ??= new ArrayClass([$this->columnName, $this->inverseColumnName])->sort(fn(string $e, string $e1): int => ComparisonResult::orderedAscending->value * ($e <=> $e1));
    }
    private(set) string $orderColumnName {
        get => $this->orderColumnName ??= $this->isReflexive ? $this->columnName : $this->columnNames[0];
    }
    private(set) SQLType $orderColumnSQLType = SQLType::int;
    public string $inverseColumnName {
        get => $this->inverseManyToMany->columnName;
    }
    private(set) string $inverseOrderColumnName {
        get => $this->inverseOrderColumnName ??= $this->isReflexive ? $this->columnName : $this->columnNames[1];
    }
    private(set) bool $isReflexive {
        get => $this->isReflexive ??= $this->columnName === $this->inverseColumnName;
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
