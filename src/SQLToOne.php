<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 10:35
 */

namespace Sabatier\CoreData;

/** @internal */
final class SQLToOne extends SQLRelationship
{
    private(set) SQLForeignKey $foreignKey {
        get => $this->foreignKey ??= new SQLForeignKey($this->entity, $this->relationshipDescription, $this);
    }
    private(set) SQLForeignEntityKey $foreignEntityKey {
        get => $this->foreignEntityKey ??= new SQLForeignEntityKey($this->entity, $this->relationshipDescription, $this->foreignKey);
    }
    private(set) SQLForeignOrderKey $foreignOrderKey {
        get => $this->foreignOrderKey ??= new SQLForeignOrderKey($this->entity, $this->relationshipDescription->inverseRelationship, new SQLForeignKey($this->entity, $this->relationshipDescription->inverseRelationship, $this));
    }
    public bool $isVirtual {
        get => false;
    }
}
