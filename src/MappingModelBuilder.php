<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 17/08/20
 * Time: 13:33
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Expression;

/** @internal */
class MappingModelBuilder
{
    /** @internal */
    public static int $migrationDebugLevel = 0;

    public function __construct(private readonly ManagedObjectModel $sourceModel, private readonly ManagedObjectModel $destinationModel)
    {
    }

    public function canTransformAttributeType(AttributeType $source, AttributeType $destination): bool
    {
        return match ($source) {
            AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float, AttributeType::boolean => $destination == AttributeType::integer16 || $destination == AttributeType::integer32 || $destination == AttributeType::integer64 || $destination == AttributeType::decimal || $destination == AttributeType::double || $destination == AttributeType::float || $destination == AttributeType::string,
            default => $source == $destination,
        };
    }

    public function newInferredAttributeMapping(?AttributeDescription $source, ?AttributeDescription $destination): ?PropertyMapping
    {
        if (!$destination || $destination instanceof DerivedAttributeDescription) {
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
        /** @psalm-suppress PossiblyInvalidPropertyAssignmentValue */
        $mapping->attributeMappings = $attributeMappings;
        /** @psalm-suppress PossiblyInvalidPropertyAssignmentValue */
        $mapping->relationshipMappings = $relationshipMappings;
        return true;
    }

    /** @noinspection PhpUnused */
    public function checkForSchemaMatchBetween(/** @noinspection PhpUnusedParameterInspection */ ?EntityDescription $source, ?EntityDescription $destination): bool
    {
        return true;
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
            if ($source) {
                if ($destination->versionHash === $source->versionHash) {
                    $mapping->mappingType = EntityMappingType::copyEntityMappingType;
                } else {
                    $mapping->mappingType = EntityMappingType::transformEntityMappingType;
                }
            } else {
                $mapping->mappingType = EntityMappingType::addEntityMappingType;
            }
        } else {
            if ($source) {
                $mapping->mappingType = EntityMappingType::removeEntityMappingType;
            }
        }
        return $mapping;
    }

    public function newInferredMappingModel(): MappingModel
    {
        $sourceEntities = $this->sourceModel->entities;
        $destinationEntities = $this->destinationModel->entities;
        /** @var ArrayClass<EntityMapping> $entityMappings */
        $entityMappings = $sourceEntities->compactMap(fn(EntityDescription $sourceEntity): ?EntityMapping => ($entityMapping = $this->newEntityMapping($sourceEntity, $this->destinationModel->entitiesByName->first(fn(EntityDescription $e): bool => $e->renamingIdentifier === $sourceEntity->renamingIdentifier))) && $this->inferPropertyMappingsForEntityMapping($entityMapping) ? $entityMapping : null);
        /** @psalm-suppress InvalidArgument */
        $entityMappings->appendContentsOf($destinationEntities->compactMap(fn(EntityDescription $destinationEntity): ?EntityMapping => !$sourceEntities->containsElement($destinationEntity) && ($entityMapping = $this->newEntityMapping(null, $destinationEntity)) && $this->inferPropertyMappingsForEntityMapping($entityMapping) ? $entityMapping : null));
        $mappingModel = new MappingModel();
        $mappingModel->entityMappings = $entityMappings;
        return $mappingModel;
    }
}
