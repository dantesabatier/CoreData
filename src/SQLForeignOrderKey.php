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
    public string $columnName {
        get => $this->columnName ??= $this->relationshipDescription->destinationEntity->attributesByName->first?->name ?? SQLEntity::primaryKeyName;
    }
    public SQLToOne $toOneRelationship {
        get => $this->foreignKey->toOneRelationship;
    }
    public RelationshipDescription $relationshipDescription {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        get => $this->propertyDescription;
    }
    private(set) SQLForeignKey $foreignKey;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $relationshipDescription);
        $this->foreignKey = $foreignKey;
    }
}
