<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:46
 */

namespace Sabatier\CoreData;

use Override;
use function Sabatier\Foundation\fatal_error;

/** @internal */
abstract class SQLRelationship extends SQLProperty
{
    public readonly RelationshipDescription $relationshipDescription;
    public readonly SQLEntity $destinationEntity;
    public readonly SQLRelationship $inverseRelationship;
    public readonly bool $isOrdered;
    public string $lazyDestinationEntityName;
    public string $lazyInverseRelationshipName;

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription)
    {
        parent::__construct($entity, $relationshipDescription);
        unset($this->relationshipDescription);
        unset($this->destinationEntity);
        unset($this->inverseRelationship);
        unset($this->isOrdered);
        unset($this->lazyDestinationEntityName);
        unset($this->lazyInverseRelationshipName);
    }

    #[Override]
    public function __get(string $name)
    {
        if ($name === "relationshipDescription") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->propertyDescription;
            return $this->$name;
        }
        if ($name === "isOrdered") {
            $this->$name = $this->relationshipDescription->isOrdered;
            return $this->$name;
        }
        if ($name === "lazyDestinationEntityName") {
            $this->$name = $this->relationshipDescription->destinationEntity->name;
            return $this->$name;
        }
        if ($name === "lazyInverseRelationshipName") {
            $this->$name = $this->relationshipDescription->inverseRelationship->name;
            return $this->$name;
        }
        if ($name === "destinationEntity") {
            /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
            $this->$name = $this->entity->model->entitiesByName[$this->lazyDestinationEntityName] ?? fatal_error("$this->name, destination entity \"$this->lazyDestinationEntityName\" does not exists");
            return $this->$name;
        }
        if ($name === "inverseRelationship") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->destinationEntity->propertiesByName[$this->lazyInverseRelationshipName] ?? fatal_error("$this->name, inverse relationship \"$this->lazyInverseRelationshipName\" does not exists");
            return $this->$name;
        }
        return parent::__get($name);
    }

    #[Override]
    public function __set(string $name, mixed $value): void
    {
        if ($name === "relationshipDescription" || $name === "isOrdered" || $name === "lazyDestinationEntityName" || $name === "lazyInverseRelationshipName" || $name === "destinationEntity" || $name === "inverseRelationship") {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }
}
