<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 10:35
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLToOne extends SQLRelationship
{
    public readonly SQLForeignKey $foreignKey;
    public readonly SQLForeignEntityKey $foreignEntityKey;
    public readonly SQLForeignOrderKey $foreignOrderKey;
    public readonly bool $isVirtual;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription)
    {
        parent::__construct($entity, $relationshipDescription);
        $isVirtual = false;
        $relationshipDescription = $this->relationshipDescription;
        if ($rootEntity = $relationshipDescription->destinationEntity->rootEntity) {
            $relationshipDescription = clone $relationshipDescription;
            $relationshipDescription->destinationEntity = $rootEntity;
            $this->lazyDestinationEntityName = $relationshipDescription->destinationEntity->name;
            $isVirtual = true;
        }
        $this->foreignKey = new SQLForeignKey($entity, $relationshipDescription, $this);
        $this->foreignEntityKey = new SQLForeignEntityKey($entity, $this->relationshipDescription, $this->foreignKey);
        $this->foreignOrderKey = new SQLForeignOrderKey($entity, $relationshipDescription->inverseRelationship, new SQLForeignKey($entity, $relationshipDescription->inverseRelationship, $this));
        $this->isVirtual = $isVirtual;
    }
}
