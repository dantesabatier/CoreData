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
use function Sabatier\Foundation\human_readable_value;

/** @internal */
abstract class SQLRelationship extends SQLProperty
{
    public RelationshipDescription $relationshipDescription {
        get {
            /** @var RelationshipDescription $relationshipDescription */
            $relationshipDescription = $this->propertyDescription;
            return $relationshipDescription;
        }
    }
    private(set) SQLEntity $destinationEntity {
        get => $this->destinationEntity ??= $this->entity->model->entitiesByName[$this->lazyDestinationEntityName] ?? fatal_error("$this->name, destination entity \"$this->lazyDestinationEntityName\" does not exists");
    }
    private(set) SQLRelationship $inverseRelationship {
        get => $this->inverseRelationship ??= $this->destinationEntity->propertiesByName[$this->lazyInverseRelationshipName] ?? fatal_error("$this->name, inverse relationship \"$this->lazyInverseRelationshipName\" does not exists");
    }
    public bool $isOrdered {
        get => $this->relationshipDescription->isOrdered;
    }
    public string $lazyDestinationEntityName {
        get => $this->relationshipDescription->destinationEntity->name;
    }
    public string $lazyInverseRelationshipName {
        get => $this->relationshipDescription->inverseRelationship->name;
    }
    public int $minCount {
        get => $this->relationshipDescription->minCount;
    }
    public int $maxCount {
        get => $this->relationshipDescription->maxCount;
    }
    public DeleteRule $deleteRule {
        get => $this->relationshipDescription->deleteRule;
    }
    #[Override]
    public string $description {
        get => sprintf("%s destinationEntityName %s InverseRelationshipName %s minCount %s maxCount %s deleteRule %s", parent::$description::get(), $this->lazyDestinationEntityName, $this->lazyInverseRelationshipName, $this->minCount, $this->maxCount, human_readable_value($this->deleteRule));
    }

    public function __construct(SQLEntity $entity, RelationshipDescription $relationshipDescription)
    {
        parent::__construct($entity, $relationshipDescription);
    }
}
