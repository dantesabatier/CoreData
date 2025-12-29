<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:12
 */

namespace Sabatier\CoreData;

/** @internal */
final class SQLPrimaryKey extends SQLColumn
{
    public PropertyDescriptionType $propertyType {
        get => PropertyDescriptionType::private;
    }
    public SQLType $sqlType {
        get => SQLType::int;
    }
}
