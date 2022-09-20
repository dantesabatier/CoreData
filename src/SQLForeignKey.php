<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:49
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLForeignKey extends SQLColumn
{
    public function __construct(SQLEntity $entity, public readonly RelationshipDescription $relationshipDescription, public readonly SQLToOne $toOneRelationship)
    {
        parent::__construct($entity, $this->relationshipDescription);
        $this->columnName = "{$this->relationshipDescription->name}ID";
        $this->propertyType = PropertyDescriptionType::attribute;
        $this->sqlType = SQLType::int;
    }
}
