<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:16
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLForeignEntityKey extends SQLColumn
{
    protected(set) string $name {
        get => $this->name ??= $this->relationshipDescription->destinationEntity->name;
    }
    protected(set) string $columnName {
        get => $this->columnName ??= $this->entity->entityKey->columnName;
    }
    public SQLToOne $toOneRelationship {
        get => $this->foreignKey->toOneRelationship;
    }
    public RelationshipDescription $relationshipDescription {
        get {
            /** @var RelationshipDescription $relationshipDescription */
            $relationshipDescription = $this->propertyDescription;
            return $relationshipDescription;
        }
    }
    public readonly SQLForeignKey $foreignKey;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $relationshipDescription);
        $this->foreignKey = $foreignKey;
    }
}
