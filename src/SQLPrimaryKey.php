<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:12
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLPrimaryKey extends SQLColumn
{
    public PropertyDescriptionType $propertyType {
        get => PropertyDescriptionType::private;
    }
    protected(set) string $columnName {
        get => $this->columnName ??= $this->name;
    }
    protected(set) SQLType $sqlType = SQLType::int;
}
