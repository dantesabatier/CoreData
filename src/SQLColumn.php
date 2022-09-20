<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:43
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLColumn extends SQLProperty
{
    public string $columnName;
    public readonly int $precision;
    public readonly int $scale;
    public readonly string $length;

    public function __construct(SQLEntity $entity, PropertyDescription $propertyDescription)
    {
        parent::__construct($entity, $propertyDescription);
        unset($this->columnName);
        unset($this->precision);
        unset($this->scale);
        unset($this->length);
    }

    public function __get(string $name)
    {
        if ($name == 'columnName') {
            $this->$name = $this->propertyDescription->name;
            return $this->$name;
        } elseif ($name == 'precision') {
            $this->$name = match ($this->sqlType) {
                SQLType::tinyint => 1,
                SQLType::smallint => 6,
                SQLType::int => 11,
                SQLType::bigint => 20,
                SQLType::decimal, SQLType::double => 10,
                SQLType::char => 80,
                SQLType::varchar => 255,
                SQLType::varbinary => 9999,
                default => 0,
            };
            return $this->$name;
        } elseif ($name == 'scale') {
            $this->$name = match ($this->sqlType) {
                SQLType::decimal, SQLType::double => 2,
                default => 0
            };
            return $this->$name;
        } elseif ($name == 'length') {
            $length = $this->precision ? "$this->precision" : "";
            if ($length && $this->scale) {
                $length .= ",$this->scale";
            }
            $this->$name = $length;
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == 'columnName' || $name == 'precision' || $name == 'scale' || $name == 'length') {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }

    public function description(): string
    {
        return sprintf("<%s %s>", $this->columnName, $this->hash());
    }
}
