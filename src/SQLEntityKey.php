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
    public function __construct(SQLEntity $entity, PropertyDescription $propertyDescription)
    {
        parent::__construct($entity, $propertyDescription);
        $this->propertyType = PropertyDescriptionType::unknown;
        $this->sqlType = SQLType::varchar;
    }
}
