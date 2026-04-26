<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:12
 */
namespace Sabatier\CoreData;

use Override;

/** @internal */
final class SQLPrimaryKey extends SQLColumn
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
