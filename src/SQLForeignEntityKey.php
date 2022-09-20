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

    public function __construct(SQLEntity $entity, public readonly RelationshipDescription $relationshipDescription, public readonly SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $this->relationshipDescription);
        $this->toOneRelationship = $this->foreignKey->toOneRelationship;
        $this->name = $this->relationshipDescription->destinationEntity->name;
        $this->columnName = $this->entity->entityKey->columnName;
    }
}
