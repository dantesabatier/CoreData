<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:46
 */

namespace Sabatier\CoreData;

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

    public function __get(string $name)
    {
        if ($name == "relationshipDescription") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->propertyDescription;
            return $this->$name;
        } elseif ($name == "isOrdered") {
            $this->$name = $this->relationshipDescription->isOrdered;
            return $this->$name;
        } elseif ($name == "lazyDestinationEntityName") {
            $this->$name = $this->relationshipDescription->destinationEntity->name;
            return $this->$name;
        } elseif ($name == "lazyInverseRelationshipName") {
            $this->$name = $this->relationshipDescription->inverseRelationship->name;
            return $this->$name;
        } elseif ($name == "destinationEntity") {
            /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
            $this->$name = $this->entity->model->entitiesByName[$this->lazyDestinationEntityName];
            return $this->$name;
        } elseif ($name == "inverseRelationship") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->destinationEntity->propertiesByName[$this->lazyInverseRelationshipName];
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == "relationshipDescription" || $name == "isOrdered" || $name == "lazyDestinationEntityName" || $name == "lazyInverseRelationshipName" || $name == "destinationEntity" || $name == "inverseRelationship") {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }
}
