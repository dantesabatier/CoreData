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
    protected(set) string $columnName {
        get => $this->columnName ??= $this->name;
    }
    public int $precision {
        get => match ($this->sqlType) {
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
    }
    public int $scale {
        get => match ($this->sqlType) {
            SQLType::decimal => 2,
            SQLType::double => 6,
            default => 0
        };
    }
    public string $length {
        get {
            $length = $this->precision ? "$this->precision" : "";
            if ($length && $this->scale) {
                $length .= ",$this->scale";
            }
            return $length;
        }
    }
    public mixed $defaultValue {
        get => $this->isOptional ? null : match ($this->sqlType) {
            SQLType::tinyint, SQLType::smallint, SQLType::mediumint, SQLType::int, SQLType::bigint => 0,
            SQLType::decimal, SQLType::float, SQLType::double => 0.0,
            SQLType::binary, SQLType::tinyblob, SQLType::blob, SQLType::mediumblob, SQLType::longblob, SQLType::bit, SQLType::text, SQLType::char, SQLType::varchar, SQLType::varbinary => "",
            SQLType::timestamp => "CURRENT_TIMESTAMP",
            SQLType::uuid => "UUID()",
            SQLType::unknown => null
        };
    }
    public string $description {
        get => sprintf("%s, precision %s, scale %s, length %s", parent::$description::get(), $this->precision, $this->scale, $this->length);
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
