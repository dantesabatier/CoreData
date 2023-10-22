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
    public readonly SQLToOne $toOneRelationship;
    public readonly RelationshipDescription $relationshipDescription;
    public readonly SQLForeignKey $foreignKey;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $relationshipDescription);
        $this->toOneRelationship = $foreignKey->toOneRelationship;
        $this->foreignKey = $foreignKey;
        $this->relationshipDescription = $relationshipDescription;
        $this->name = $relationshipDescription->destinationEntity->name;
        $this->columnName = $entity->entityKey->columnName;
    }
}
