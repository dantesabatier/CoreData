<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:43
 */

namespace Sabatier\CoreData;

use Override;

/** @internal */
class SQLColumn extends SQLProperty
{
    public string $columnName;
    public readonly int $precision;
    public readonly int $scale;
    public readonly string $length;
    public readonly mixed $defaultValue;

    public function __construct(SQLEntity $entity, PropertyDescription $propertyDescription)
    {
        parent::__construct($entity, $propertyDescription);
        unset($this->columnName);
        unset($this->precision);
        unset($this->scale);
        unset($this->length);
        unset($this->defaultValue);
    }

    #[Override]
    public function __get(string $name)
    {
        if ($name === "columnName") {
            $this->$name = $this->propertyDescription->name;
            return $this->$name;
        }
        if ($name === "precision") {
            $this->$name = match ($this->sqlType) {
                SQLType::tinyint => 1,
                SQLType::smallint => 6,
                SQLType::int => 11,
                SQLType::bigint => 20,
                SQLType::decimal, SQLType::double => 10,
                SQLType::binary, SQLType::char => 80,
                SQLType::varchar => 255,
                SQLType::varbinary => 9999,
                default => 0
            };
            return $this->$name;
        }
        if ($name === "scale") {
            $this->$name = match ($this->sqlType) {
                SQLType::decimal => 2,
                SQLType::double => 6,
                default => 0
            };
            return $this->$name;
        }
        if ($name === "length") {
            $length = $this->precision ? "$this->precision" : "";
            if ($length && $this->scale) {
                $length .= ",$this->scale";
            }
            $this->$name = $length;
            return $this->$name;
        }
        if ($name === "defaultValue") {
            $this->$name = $this->isOptional ? null : match ($this->sqlType) {
                SQLType::tinyint, SQLType::smallint, SQLType::mediumint, SQLType::int, SQLType::bigint => 0,
                SQLType::decimal, SQLType::float, SQLType::double => 0.0,
                SQLType::binary, SQLType::tinyblob, SQLType::blob, SQLType::mediumblob, SQLType::longblob, SQLType::bit, SQLType::text, SQLType::char, SQLType::varchar, SQLType::varbinary => "",
                SQLType::timestamp => "CURRENT_TIMESTAMP",
                SQLType::uuid => "UUID()",
                SQLType::unknown => null
            };
            return $this->$name;
        }
        return parent::__get($name);
    }

    #[Override]
    public function __set(string $name, mixed $value): void
    {
        if ($name === "columnName" || $name === "precision" || $name === "scale" || $name === "length" || $name === "defaultValue") {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }

    #[Override]
    public function description(): string
    {
        return sprintf("<%s %s>", $this->columnName, $this->hash());
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLColumn) {
            return $this->columnName === $other->columnName;
        }
        return parent::isEqual($other);
    }
}
