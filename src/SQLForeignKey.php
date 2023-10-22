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
    public readonly RelationshipDescription $relationshipDescription;
    public readonly SQLToOne $toOneRelationship;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, SQLToOne $toOneRelationship)
    {
        parent::__construct($entity, $relationshipDescription);
        $this->toOneRelationship = $toOneRelationship;
        $this->relationshipDescription = $relationshipDescription;
        $this->columnName = "{$relationshipDescription->name}ID";
        $this->propertyType = PropertyDescriptionType::relationship;
        $this->sqlType = SQLType::int;
    }
}
