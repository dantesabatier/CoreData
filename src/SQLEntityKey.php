<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:15
 */

namespace Sabatier\CoreData;

use Override;

/** @internal */
final class SQLEntityKey extends SQLColumn
{
    #[Override]
    public PropertyDescriptionType $propertyType {
        get => PropertyDescriptionType::private;
    }
    #[Override]
    public SQLType $sqlType {
        get => SQLType::varchar;
    }
    #[Override]
    public mixed $defaultValue {
        get => $this->entity->entityDescription->name;
    }
}
