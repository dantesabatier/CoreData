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

    public function __construct(SQLEntity $entity, public readonly RelationshipDescription $relationshipDescription, public readonly SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $this->relationshipDescription);
        $this->toOneRelationship = $this->foreignKey->toOneRelationship;
        /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
        $this->columnName = $this->relationshipDescription->destinationEntity->attributesByName->first()?->name;
    }
}
