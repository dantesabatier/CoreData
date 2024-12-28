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
    public PropertyDescriptionType $propertyType {
        get => PropertyDescriptionType::private;
    }
    public SQLType $sqlType {
        get => SQLType::varchar;
    }
    public AttributeDescription $attributeDescription {
        get {
            /** @var AttributeDescription $attributeDescription */
            $attributeDescription = $this->propertyDescription;
            return $attributeDescription;
        }
    }
    public mixed $defaultValue {
        get => $this->attributeDescription->defaultValue;
    }
}
