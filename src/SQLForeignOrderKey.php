<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:27
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLForeignOrderKey extends SQLColumn
{
    public readonly SQLToOne $toOneRelationship;
    public readonly RelationshipDescription $relationshipDescription;
    public readonly SQLForeignKey $foreignKey;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $relationshipDescription);
        $this->toOneRelationship = $foreignKey->toOneRelationship;
        /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
        $this->columnName = $relationshipDescription->destinationEntity->attributesByName->first()?->name;
        $this->foreignKey = $foreignKey;
        $this->relationshipDescription = $relationshipDescription;
    }
}
