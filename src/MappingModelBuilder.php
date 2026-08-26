<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/08/20
 * Time: 13:33
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
final class MappingModelBuilder
{
    /** @internal */
    public static int $migrationDebugLevel = 0;

    /**
     * Destination entities indexed by renaming identifier, so a source entity finds its
     * destination in O(1) instead of a linear scan. Built once; mirrors the cache that
     * _NSMappingModelBuilder resets via _resetCaches.
     * @var Dictionary<EntityDescription>
     */
    private Dictionary $destinationEntitiesByRenamingIdentifier {
        get => $this->destinationEntitiesByRenamingIdentifier ??= $this->indexByRenamingIdentifier($this->destinationModel->entitiesByName);
    }
    /**
     * Source root entities indexed by renaming identifier, so the "destination entity added"
     * check is an O(1) lookup instead of a linear contains() over the source entities.
     * @var Dictionary<EntityDescription>
     */
    private Dictionary $sourceRootEntitiesByRenamingIdentifier {
        get => $this->sourceRootEntitiesByRenamingIdentifier ??= $this->indexByRenamingIdentifier($this->sourceModel->entitiesByName->filter(fn(EntityDescription $entity): bool => $entity->isRootEntity));
    }
    /**
     * Per-destination-entity attribute indexes (renaming identifier -> attribute), keyed by the
     * destination entity name and populated lazily as each entity mapping is inferred.
     * @var Dictionary<Dictionary<AttributeDescription>>
     */
    private Dictionary $destinationAttributeIndexesByEntityName {
        get => $this->destinationAttributeIndexesByEntityName ??= new Dictionary();
    }
    /**
     * Per-destination-entity relationship indexes (renaming identifier -> relationship).
     * @var Dictionary<Dictionary<RelationshipDescription>>
     */
    private Dictionary $destinationRelationshipIndexesByEntityName {
        get => $this->destinationRelationshipIndexesByEntityName ??= new Dictionary();
    }

    public function __construct(private readonly ManagedObjectModel $sourceModel, private readonly ManagedObjectModel $destinationModel)
    {
    }

    /**
     * Indexes a by-name dictionary of descriptions by their renaming identifier. When two
     * descriptions share a renaming identifier the first wins, matching the behavior of the
     * linear first() scans this replaces.
     * @template T of EntityDescription|AttributeDescription|RelationshipDescription
     * @param Dictionary<T> $descriptionsByName
     * @return Dictionary<T>
     */
    private function indexByRenamingIdentifier(Dictionary $descriptionsByName): Dictionary
    {
        return $descriptionsByName->reduce(new Dictionary(),
            /**
             * @param Dictionary<mixed> $index
             * @param EntityDescription|AttributeDescription|RelationshipDescription $description
             * @return Dictionary<mixed>
             */
            function (Dictionary $index, EntityDescription|AttributeDescription|RelationshipDescription $description): Dictionary {
                $index[$description->renamingIdentifier] ??= $description;
                return $index;
            });
    }

    /**
     * @return Dictionary<AttributeDescription>
     */
    private function destinationAttributeIndex(EntityDescription $destinationEntity): Dictionary
    {
        /** @var Dictionary<AttributeDescription> $index */
        $index = $this->destinationAttributeIndexesByEntityName[$destinationEntity->name] ?? $this->indexByRenamingIdentifier($destinationEntity->attributesByName);
        $this->destinationAttributeIndexesByEntityName[$destinationEntity->name] ??= $index;
        return $index;
    }

    /**
     * @return Dictionary<RelationshipDescription>
     */
    private function destinationRelationshipIndex(EntityDescription $destinationEntity): Dictionary
    {
        /** @var Dictionary<RelationshipDescription> $index */
        $index = $this->destinationRelationshipIndexesByEntityName[$destinationEntity->name] ?? $this->indexByRenamingIdentifier($destinationEntity->relationshipsByName);
        $this->destinationRelationshipIndexesByEntityName[$destinationEntity->name] ??= $index;
        return $index;
    }

    public function canTransformAttributeType(AttributeType $source, AttributeType $destination): bool
    {
        if ($source === $destination) {
            return true;
        }
        return match ($source) {
            AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float, AttributeType::boolean => in_array($destination, [AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float, AttributeType::string], true),
            default => false,
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
                $destinationAttributeIndex = $this->destinationAttributeIndex($destinationEntity);
                $destinationRelationshipIndex = $this->destinationRelationshipIndex($destinationEntity);
                $attributeMappings = $sourceEntity->attributesByName->compactMap(fn(AttributeDescription $source): ?PropertyMapping => $this->newInferredAttributeMapping($source, $destinationAttributeIndex[$source->renamingIdentifier]));
                $relationshipMappings = $sourceEntity->relationshipsByName->compactMap(fn(RelationshipDescription $source): ?PropertyMapping => $this->newInferredRelationshipMapping($source, $destinationRelationshipIndex[$source->renamingIdentifier]));
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
        $destinationAttributeIndex = $this->destinationAttributeIndex($destination);
        $attributesMatch = $source->attributesByName->allSatisfy(function (AttributeDescription $sourceAttribute) use ($destinationAttributeIndex): bool {
            $destinationAttribute = $destinationAttributeIndex[$sourceAttribute->renamingIdentifier];
            if (!$destinationAttribute || $destinationAttribute->isTransient || $destinationAttribute instanceof DerivedAttributeDescription || $sourceAttribute->isTransient || $sourceAttribute instanceof DerivedAttributeDescription) {
                return true;
            }
            return $this->canTransformAttributeType($sourceAttribute->type, $destinationAttribute->type);
        });
        if (!$attributesMatch) {
            return false;
        }
        $destinationRelationshipIndex = $this->destinationRelationshipIndex($destination);
        return $source->relationshipsByName->allSatisfy(function (RelationshipDescription $sourceRelationship) use ($destinationRelationshipIndex): bool {
            $destinationRelationship = $destinationRelationshipIndex[$sourceRelationship->renamingIdentifier];
            if (!$destinationRelationship) {
                return true;
            }
            // Compare the destination entities by renaming identifier so that renaming the entity (not a real re-targeting) is not mistaken for a destination change.
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
        // Map every source entity to its destination (by renaming identifier), producing copy, transform and remove mappings.
        $entityMappings = $sourceEntities->compactMap(function (EntityDescription $sourceEntity): ?EntityMapping {
            $destinationEntity = $this->destinationEntitiesByRenamingIdentifier[$sourceEntity->renamingIdentifier];
            $entityMapping = $this->newEntityMapping($sourceEntity, $destinationEntity);
            return $entityMapping && $this->inferPropertyMappingsForEntityMapping($entityMapping) ? $entityMapping : null;
        });
        // Add mappings for destination entities that have no source counterpart.
        $addedMappings = $destinationEntities->compactMap(function (EntityDescription $destinationEntity): ?EntityMapping {
            if ($this->sourceRootEntitiesByRenamingIdentifier[$destinationEntity->renamingIdentifier]) {
                return null;
            }
            $entityMapping = $this->newEntityMapping(null, $destinationEntity);
            return $entityMapping && $this->inferPropertyMappingsForEntityMapping($entityMapping) ? $entityMapping : null;
        });
        $mappingModel = new MappingModel();
        $mappingModel->entityMappings = $entityMappings->appendingContentsOf($addedMappings);
        return $mappingModel;
    }
}
