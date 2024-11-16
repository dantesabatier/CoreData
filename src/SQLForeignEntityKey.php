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
    public string $name {
        get => $this->relationshipDescription->destinationEntity->name;
    }
    public string $columnName {
        get => $this->entity->entityKey->columnName;
    }
    public SQLToOne $toOneRelationship {
        get => $this->foreignKey->toOneRelationship;
    }
    public RelationshipDescription $relationshipDescription {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        get => $this->propertyDescription;
    }
    public readonly SQLForeignKey $foreignKey;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $relationshipDescription);
        $this->foreignKey = $foreignKey;
    }
}
