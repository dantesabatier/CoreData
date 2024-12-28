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
    public RelationshipDescription $relationshipDescription {
        get {
            /** @var RelationshipDescription $relationshipDescription */
            $relationshipDescription = $this->propertyDescription;
            return $relationshipDescription;
        }
    }
    public PropertyDescriptionType $propertyType {
        get => PropertyDescriptionType::relationship;
    }
    public string $columnName {
        get => "{$this->relationshipDescription->name}ID";
    }
    public SQLType $sqlType {
        get => SQLType::int;
    }
    public string $description {
        get => sprintf("<%s: %s>, name %s, entity %s", $this->class, $this->hash, $this->columnName, $this->entity->tableName);
    }

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, public readonly SQLToOne $toOneRelationship)
    {
        parent::__construct($entity, $relationshipDescription);
    }
}
