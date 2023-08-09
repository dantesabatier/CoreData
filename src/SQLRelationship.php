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
    public readonly SQLEntity $destinationEntity;
    public readonly SQLRelationship $inverseRelationship;
    public readonly bool $isOrdered;
    public string $lazyDestinationEntityName;
    public string $lazyInverseRelationshipName;

    public function __construct(SQLEntity $entity, public readonly RelationshipDescription $relationshipDescription)
    {
        parent::__construct($entity, $this->relationshipDescription);
        unset($this->destinationEntity);
        unset($this->inverseRelationship);
        $this->isOrdered = $this->relationshipDescription->isOrdered;
        $this->lazyInverseRelationshipName = $this->relationshipDescription->inverseRelationship->name;
        $this->lazyDestinationEntityName = $this->relationshipDescription->destinationEntity->name;
    }

    public function __get(string $name)
    {
        if ($name == "destinationEntity") {
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
        if ($name == "destinationEntity" || $name == "inverseRelationship") {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }
}
