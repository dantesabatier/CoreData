<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:16
 */
namespace Sabatier\CoreData;

use Override;

/** @internal */
final class SQLForeignEntityKey extends SQLColumn
{
    #[Override]
    protected(set) string $name {
        get => $this->name ??= $this->relationshipDescription->destinationEntity->name;
    }
    #[Override]
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
