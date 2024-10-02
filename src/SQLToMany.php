<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 10:42
 */

namespace Sabatier\CoreData;

use Override;

/** @internal */
class SQLToMany extends SQLRelationship
{
    public readonly SQLToOne $inverseToOne;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription)
    {
        parent::__construct($entity, $relationshipDescription);
        unset($this->inverseToOne);
    }

    #[Override]
    public function __get(string $name)
    {
        if ($name === "inverseToOne") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->inverseRelationship;
            return $this->$name;
        }
        return parent::__get($name);
    }

    #[Override]
    public function __set(string $name, mixed $value): void
    {
        if ($name === "inverseToOne") {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }
}
