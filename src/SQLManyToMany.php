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
    public readonly SQLManyToMany $inverseManyToMany;
    public readonly string $correlationTableName;
    public readonly string $columnName;
    public readonly SQLType $columnSQLType;
    public readonly string $orderColumnName;
    public readonly SQLType $orderColumnSQLType;
    public readonly string $inverseColumnName;
    public readonly string $inverseOrderColumnName;
    public readonly bool $isReflexive;
    public readonly bool $isMaster;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription)
    {
        parent::__construct($entity, $relationshipDescription);
        unset($this->inverseManyToMany);
        unset($this->correlationTableName);
        unset($this->columnName);
        unset($this->columnSQLType);
        unset($this->inverseColumnName);
        unset($this->orderColumnName);
        unset($this->inverseOrderColumnName);
        unset($this->isReflexive);
        unset($this->isMaster);
    }

    #[Override]
    public function __get(string $name)
    {
        if ($name === "inverseManyToMany") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->inverseRelationship;
            return $this->$name;
        }
        if ($name === "correlationTableName") {
            $this->$name = (new ArrayClass([$this->destinationEntity, $this->inverseRelationship->destinationEntity]))->sorted([new SortDescriptor("tableName")])->valueForKey("tableName")->join("");
            return $this->$name;
        }
        if ($name === "columnName") {
            $this->$name = "{$this->name}ID";
            return $this->$name;
        }
        if ($name === "columnSQLType") {
            $this->$name = SQLType::int;
            return $this->$name;
        }
        if ($name === "inverseColumnName") {
            $this->$name = $this->inverseManyToMany->columnName;
            return $this->$name;
        }
        if ($name === "orderColumnName") {
            $this->$name = $this->isReflexive ? $this->columnName : (new ArrayClass([$this->columnName, $this->inverseColumnName]))->sort(fn(string $e, string $e1): int => ComparisonResult::orderedAscending->value * ($e <=> $e1))[0];
            return $this->$name;
        }
        if ($name === "inverseOrderColumnName") {
            $this->$name = $this->isReflexive ? $this->columnName : (new ArrayClass([$this->columnName, $this->inverseColumnName]))->sort(fn(string $e, string $e1): int => ComparisonResult::orderedAscending->value * ($e <=> $e1))[1];
            return $this->$name;
        }
        if ($name === "isReflexive") {
            $this->$name = $this->columnName === $this->inverseColumnName;
            return $this->$name;
        }
        if ($name === "isMaster") {
            $this->$name = false;
            return $this->$name;
        }
        return parent::__get($name);
    }

    #[Override]
    public function __set(string $name, mixed $value): void
    {
        if ($name === "inverseManyToMany" || $name === "correlationTableName" || $name === "columnName" || $name === "columnSQLType" || $name === "inverseColumnName" || $name === "orderColumnName" || $name === "inverseOrderColumnName" || $name === "isReflexive" || $name === "isMaster") {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
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
