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
    public readonly string $correlationTableName;
    public string $columnName {
        get => "{$this->name}ID";
    }
    public SQLType $columnSQLType {
        get => SQLType::int;
    }
    public readonly string $orderColumnName;
    public readonly SQLType $orderColumnSQLType;
    public string $inverseColumnName {
        get => $this->inverseManyToMany->columnName;
    }
    public readonly string $inverseOrderColumnName;
    public bool $isReflexive {
        get => $this->columnName === $this->inverseColumnName;
    }
    public bool $isMaster {
        get => false;
    }

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription)
    {
        parent::__construct($entity, $relationshipDescription);
        unset($this->correlationTableName);
        unset($this->orderColumnName);
        unset($this->inverseOrderColumnName);
    }

    public function __get(string $name)
    {
        if ($name === "correlationTableName") {
            $this->$name = new ArrayClass([$this->destinationEntity, $this->inverseRelationship->destinationEntity])->sorted([new SortDescriptor("tableName")])->valueForKey("tableName")->join("");
            return $this->$name;
        }
        if ($name === "orderColumnName") {
            $this->$name = $this->isReflexive ? $this->columnName : new ArrayClass([$this->columnName, $this->inverseColumnName])->sort(fn(string $e, string $e1): int => ComparisonResult::orderedAscending->value * ($e <=> $e1))[0];
            return $this->$name;
        }
        if ($name === "inverseOrderColumnName") {
            $this->$name = $this->isReflexive ? $this->columnName : new ArrayClass([$this->columnName, $this->inverseColumnName])->sort(fn(string $e, string $e1): int => ComparisonResult::orderedAscending->value * ($e <=> $e1))[1];
            return $this->$name;
        }
        return $this->valueForUndefinedKey($name);
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === "correlationTableName" || $name === "orderColumnName" || $name === "inverseOrderColumnName") {
            $this->$name = $value;
        } else {
            $this->setValueForUndefinedKey($value, $name);
        }
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
