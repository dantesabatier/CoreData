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
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::relationship;
    public string $columnName {
        get => "{$this->relationshipDescription->name}ID";
    }
    public RelationshipDescription $relationshipDescription {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        get => $this->propertyDescription;
    }
    public SQLType $sqlType = SQLType::int;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, public readonly SQLToOne $toOneRelationship)
    {
        parent::__construct($entity, $relationshipDescription);
    }
}
