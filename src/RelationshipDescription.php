<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;

/**
 * A description of a relationship of a Core Data entity.
 *
 * RelationshipDescription extends PropertyDescription to describe features appropriate to relationships, including cardinality (the number of objects allowed in the relationship), the destination entity, and delete rules.
 */
final class RelationshipDescription extends PropertyDescription
{
    /** @internal */
    #[Override]
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::relationship;
    /** @var EntityDescription The entity description of the receiver's destination. */
    private(set) EntityDescription $destinationEntity {
        get {
            if (isset($this->destinationEntity)) {
                return $this->destinationEntity;
            }
            !$this->entity->isEditable ?: fatal_error(sprintf("%s property \"%s\" cannot be accessed before initialization", $this->debugDescription, __PROPERTY__));
            /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
            return $this->destinationEntity = $this->entity->managedObjectModel->entitiesByName[$this->lazyDestinationEntityName] ?? $this->entity->managedObjectModel->entitiesByName->first(fn(EntityDescription $entity): bool => $entity->renamingIdentifier === $this->lazyDestinationEntityName) ?? fatal_error("$this->name, destination entity \"$this->lazyDestinationEntityName\" does not exists");
        }
    }
    /** @var RelationshipDescription The relationship that represents the inverse of the receiver. */
    private(set) RelationshipDescription $inverseRelationship {
        get {
            if (isset($this->inverseRelationship)) {
                return $this->inverseRelationship;
            }
            !$this->entity->isEditable ?: fatal_error(sprintf("%s property \"%s\" cannot be accessed before initialization", $this->debugDescription, __PROPERTY__));
            /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
            return $this->inverseRelationship = $this->destinationEntity->relationshipsByName[$this->lazyInverseRelationshipName] ?? $this->destinationEntity->relationshipsByName->first(fn(RelationshipDescription $relationship): bool => $relationship->renamingIdentifier === $this->lazyInverseRelationshipName) ?? fatal_error("$this->name, inverse relationship \"$this->lazyInverseRelationshipName\" does not exists");
        }
    }
    /** @var bool Returns a Boolean value that indicates whether the relationship can contain many managed objects. If {@see maxCount} is equal to 1, implying a to-one relationship, this property returns false; otherwise, it returns true. */
    public bool $isToMany = false;
    /** @var bool A Boolean value that determines whether the relationship preserves the order of the referenced managed objects. The default value is false. */
    public bool $isOrdered = false;
    /** @var DeleteRule The rule to apply when you delete the relationship's owning managed object. The default value is {@see DeleteRule::nullifyDeleteRule}. For possible values, see {@see DeleteRule}. */
    public DeleteRule $deleteRule = DeleteRule::nullifyDeleteRule {
        set(DeleteRule|int $value) {
            if (is_int($value)) {
                $value = DeleteRule::from($value);
            }
            $this->deleteRule = $value;
        }
    }
    /** @var int The minimum number of managed objects the relationship can reference. If you declare a relationship attribute as optional when defining your entities, the framework only enforces minCount and {@see maxCount} when that attribute is not null. The default value is 0. */
    public int $minCount = 0;
    /** @var int The maximum number of managed objects the relationship can reference. If you declare a relationship attribute as optional when defining your entities, the framework only enforces {@see minCount} and maxCount when that attribute is not null. The default value is 0. */
    public int $maxCount = 0;
    /** @internal */
    public string $lazyDestinationEntityName = UnknownName;
    /** @internal */
    public string $lazyInverseRelationshipName = UnknownName;
    #[Override]
    public string $description {
        get => sprintf("%s destinationEntityName %s InverseRelationshipName %s minCount %s maxCount %s deleteRule %s", parent::$description::get(), $this->lazyDestinationEntityName, $this->lazyInverseRelationshipName, $this->minCount, $this->maxCount, human_readable_value($this->deleteRule));
    }

    #[Override]
    public function versionHashInStyle(?string &$out, VersionHashStyle $style): void
    {
        parent::versionHashInStyle($data, $style);
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = KeyedUnarchiver::unarchiveTopLevelObjectWithData((string)$data);
        if ($this->deleteRule !== DeleteRule::nullifyDeleteRule) {
            $dictionary["deleteRule"] = $this->deleteRule->value;
        }
        // Cardinality is part of the relationship's persistent shape: a to-one relationship is a foreign-key column while a to-many (whose inverse is to-many) is a pivot table, so a change here demands a migration. Include min/max count in the hash so that a cardinality change alters the entity version hash and is inferred as a transform rather than a copy.
        $dictionary["minCount"] = $this->minCount;
        $dictionary["maxCount"] = $this->maxCount;
        $dictionary["lazyDestinationEntityName"] = $this->lazyDestinationEntityName;
        $dictionary["lazyInverseRelationshipName"] = $this->lazyInverseRelationshipName;
        $out = KeyedArchiver::archivedData($dictionary);
    }
}
