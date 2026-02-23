<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:33
 */

namespace Sabatier\CoreData;

use Override;

/** @internal */
final class SQLOptLockKey extends SQLColumn
{
    #[Override]
    public PropertyDescriptionType $propertyType {
        get => PropertyDescriptionType::private;
    }
    #[Override]
    public SQLType $sqlType {
        get => SQLType::int;
    }
}
