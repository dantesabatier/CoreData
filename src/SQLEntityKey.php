<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:15
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLEntityKey extends SQLColumn
{
    protected(set) PropertyDescriptionType $propertyType {
        get => $this->propertyType ??= PropertyDescriptionType::private;
    }
    protected(set) SQLType $sqlType = SQLType::varchar;
}
