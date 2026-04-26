<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:27
 */
namespace Sabatier\CoreData;

use Override;

/** @internal */
final class SQLForeignOrderKey extends SQLColumn
{
    public RelationshipDescription $relationshipDescription {
        get {
            /** @var RelationshipDescription $relationshipDescription */
            $relationshipDescription = $this->propertyDescription;
            return $relationshipDescription;
        }
    }
    #[Override]
    public string $columnName {
        get => $this->columnName ??= $this->relationshipDescription->destinationEntity->attributesByName->first?->name ?? ManagedObjectObjectIDKey;
    }
    public readonly SQLForeignKey $foreignKey;
    public SQLToOne $toOneRelationship {
        get => $this->foreignKey->toOneRelationship;
    }

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription, SQLForeignKey $foreignKey)
    {
        parent::__construct($entity, $relationshipDescription);
        $this->foreignKey = $foreignKey;
    }
}
