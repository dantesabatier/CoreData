<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/08/20
 * Time: 13:33
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
final class MappingModelBuilder
{
    /** @internal */
    public static int $migrationDebugLevel = 0;

    public function __construct(private readonly ManagedObjectModel $sourceModel, private readonly ManagedObjectModel $destinationModel)
    {
    }

    public function canTransformAttributeType(AttributeType $source, AttributeType $destination): bool
    {
        return match ($source) {
            AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float, AttributeType::boolean => in_array($destination, [AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float, AttributeType::string], true),
            default => $source === $destination,
        };
    }

    public function newInferredAttributeMapping(?AttributeDescription $source, ?AttributeDescription $destination): ?PropertyMapping
    {
        if (!$destination || $destination->isTransient || $destination instanceof DerivedAttributeDescription) {
            return null;
        }
        if (!$source) {
            return new PropertyMapping($destination->name);
        }
        if (!$this->canTransformAttributeType($source->type, $destination->type)) {
            return null;
        }
        return new PropertyMapping($destination->name, Expression::expressionWithFormat("\$source.%s", new ArrayClass([$source->name])));
    }

    public function newInferredRelationshipMapping(?RelationshipDescription $source, ?RelationshipDescription $destination): ?PropertyMapping
    {
        if (!$destination) {
            return null;
        }
        if (!$source) {
            return new PropertyMapping($destination->name);
        }
        return new PropertyMapping($destination->name, Expression::expressionWithFormat("FUNCTION(\$manager, destinationInstances, %s, \$source)", new ArrayClass(["{$source->destinationEntity->name}To{$destination->destinationEntity->name}"])));
    }

    public function inferPropertyMappingsForEntityMapping(EntityMapping $mapping): bool
    {
        $sourceEntityName = $mapping->sourceEntityName;
        $destinationEntityName = $mapping->destinationEntityName;
        /** @var EntityDescription|null $sourceEntity */
        $sourceEntity = $sourceEntityName ? $this->sourceModel->entitiesByName[$sourceEntityName] : null;
        /** @var EntityDescription|null $destinationEntity */
        $destinationEntity = $destinationEntityName ? $this->destinationModel->entitiesByName[$destinationEntityName] : null;
        if (!$sourceEntity && !$destinationEntity) {
            return false;
        }
        /** @var ArrayClass<PropertyMapping> $attributeMappings */
        $attributeMappings = new ArrayClass();
        /** @var ArrayClass<PropertyMapping> $relationshipMappings */
        $relationshipMappings = new ArrayClass();
        if ($destinationEntity) {
            if ($sourceEntity) {
                $attributeMappings = $sourceEntity->attributesByName->compactMap(fn(AttributeDescription $source): ?PropertyMapping => $this->newInferredAttributeMapping($source, $destinationEntity->attributesByName->first(fn(AttributeDescription $destination): bool => $destination->renamingIdentifier === $source->renamingIdentifier)));
                $relationshipMappings = $sourceEntity->relationshipsByName->compactMap(fn(RelationshipDescription $source): ?PropertyMapping => $this->newInferredRelationshipMapping($source, $destinationEntity->relationshipsByName->first(fn(RelationshipDescription $destination): bool => $destination->renamingIdentifier === $source->renamingIdentifier)));
            } else {
                $attributeMappings = $destinationEntity->attributesByName->map(fn(AttributeDescription $attribute): PropertyMapping => new PropertyMapping($attribute->name));
                $relationshipMappings = $destinationEntity->relationshipsByName->map(fn(RelationshipDescription $relationship): PropertyMapping => new PropertyMapping($relationship->name));
            }
        }
        $mapping->attributeMappings = $attributeMappings;
        $mapping->relationshipMappings = $relationshipMappings;
        return true;
    }

    /**
     * Returns whether a lightweight (inferred) migration can map $source to $destination without
     * manual intervention. It is a pure predicate: it inspects the properties the two entities
     * share (paired by renaming identifier) and reports the two changes an inferred mapping
     * cannot express —
     *  - an attribute whose type changes in a way {@see canTransformAttributeType()} rejects
     *    (there is no automatic value conversion), and
     *  - a relationship whose destination entity changes (the migrator cannot re-target the
     *    related objects).
     *
     * Added, removed, and renamed properties, transformable type changes, optionality changes,
     * and new mandatory attributes (filled with their type default) are all inferable and return
     * true.
     */
    public function checkForSchemaMatchBetween(?EntityDescription $source, ?EntityDescription $destination): bool
    {
        if (!$source || !$destination) {
            return true;
        }
        $attributesMatch = $source->attributesByName->allSatisfy(function (AttributeDescription $sourceAttribute) use ($destination): bool {
            $destinationAttribute = $destination->attributesByName->first(fn(AttributeDescription $candidate): bool => $candidate->renamingIdentifier === $sourceAttribute->renamingIdentifier);
            if (!$destinationAttribute || $destinationAttribute->isTransient || $destinationAttribute instanceof DerivedAttributeDescription || $sourceAttribute->isTransient || $sourceAttribute instanceof DerivedAttributeDescription) {
                return true;
            }
            return $this->canTransformAttributeType($sourceAttribute->type, $destinationAttribute->type);
        });
        if (!$attributesMatch) {
            return false;
        }
        return $source->relationshipsByName->allSatisfy(function (RelationshipDescription $sourceRelationship) use ($destination): bool {
            $destinationRelationship = $destination->relationshipsByName->first(fn(RelationshipDescription $candidate): bool => $candidate->renamingIdentifier === $sourceRelationship->renamingIdentifier);
            if (!$destinationRelationship) {
                return true;
            }
            // Compare the destination entities by renaming identifier so that renaming the target
            // entity (not a real re-targeting) is not mistaken for a destination change.
            return $sourceRelationship->destinationEntity->renamingIdentifier === $destinationRelationship->destinationEntity->renamingIdentifier;
        });
    }

    public function newEntityMapping(?EntityDescription $source, ?EntityDescription $destination): ?EntityMapping
    {
        if ($source?->isPersistentHistoryEntity || $destination?->isPersistentHistoryEntity) {
            return null;
        }
        $mapping = new EntityMapping();
        if ($source) {
            $mapping->sourceEntityName = $source->name;
            $mapping->sourceEntityVersionHash = $source->versionHash;
        }
        if ($destination) {
            $mapping->destinationEntityName = $destination->name;
            $mapping->destinationEntityVersionHash = $destination->versionHash;
            if ($source && $destination->versionHash !== $source->versionHash && !$this->checkForSchemaMatchBetween($source, $destination)) {
                throw new InferredMappingModelException("Cannot infer a mapping from entity \"$source->name\" to \"$destination->name\": the change is not expressible as a lightweight migration");
            }
            $mapping->mappingType = $source ? ($destination->versionHash === $source->versionHash ? EntityMappingType::copyEntityMappingType : EntityMappingType::transformEntityMappingType) : EntityMappingType::addEntityMappingType;
        } elseif ($source) {
            $mapping->mappingType = EntityMappingType::removeEntityMappingType;
        }
        return $mapping;
    }

    public function newInferredMappingModel(): MappingModel
    {
        $sourceEntities = $this->sourceModel->entitiesByName->filter(fn(EntityDescription $entity): bool => $entity->isRootEntity);
        $destinationEntities = $this->destinationModel->entitiesByName->filter(fn(EntityDescription $entity): bool => $entity->isRootEntity);
        /** @var ArrayClass<EntityMapping> $entityMappings */
        $entityMappings = $sourceEntities->compactMap(fn(EntityDescription $sourceEntity): ?EntityMapping => ($entityMapping = $this->newEntityMapping($sourceEntity, $this->destinationModel->entitiesByName->first(fn(EntityDescription $e): bool => $e->renamingIdentifier === $sourceEntity->renamingIdentifier))) && $this->inferPropertyMappingsForEntityMapping($entityMapping) ? $entityMapping : null)->appendingContentsOf($destinationEntities->compactMap(fn(EntityDescription $destinationEntity): ?EntityMapping => !$sourceEntities->contains(fn(EntityDescription $sourceEntity): bool => $destinationEntity->renamingIdentifier === $sourceEntity->renamingIdentifier) && ($entityMapping = $this->newEntityMapping(null, $destinationEntity)) && $this->inferPropertyMappingsForEntityMapping($entityMapping) ? $entityMapping : null));
        $mappingModel = new MappingModel();
        $mappingModel->entityMappings = $entityMappings;
        return $mappingModel;
    }
}
