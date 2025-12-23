<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:40
 */

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\PropertyListSerialization;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\fatal_error;

/**
 * A model instance that specifies how to map a model from a source to a destination managed object model.
 */
class MappingModel extends ObjectClass
{
    /** @internal */
    public static int $migrationDebugLevel = 0;
    /** @var Dictionary<string> */
    private(set) Dictionary $sourceEntityVersionHashesByName {
        get => $this->sourceEntityVersionHashesByName ??= new Dictionary();
    }
    /** @var Dictionary<string> */
    private(set) Dictionary $destinationEntityVersionHashesByName {
        get => $this->destinationEntityVersionHashesByName ??= new Dictionary();
    }
    /** @var Dictionary<EntityMapping> $entityMappingsByName The entity mappings for the mapping model, keyed by name. */
    private(set) Dictionary $entityMappingsByName {
        get => $this->entityMappingsByName ??= new Dictionary();
    }
    /** @var ArrayClass<EntityMapping> $entityMappings The entity mappings for the mapping model. */
    public ArrayClass $entityMappings {
        get => $this->entityMappingsByName->values;
        set {
            $this->sourceEntityVersionHashesByName->removeAll();
            $this->destinationEntityVersionHashesByName->removeAll();
            $this->entityMappingsByName->removeAll();
            foreach ($value as $entityMapping) {
                $this->addEntityMapping($entityMapping);
            }
        }
    }

    /**
     * Returns a mapping model initialized from a given URL.
     * @param URL|null $url The location of an archived mapping model.
     * @throws Exception
     */
    public function __construct(?URL $url = null)
    {
        if ($url) {
            //FIXME: implement url loading, test it and improve it
            /** @var Dictionary<mixed> $dictionary */
            $dictionary = PropertyListSerialization::propertyListWithURL($url);
            /** @var ArrayClass<Dictionary<mixed>>|null $entities */
            $entities = $dictionary["entities"];
            if ($entities) {
                $this->entityMappings = $entities->map(function (Dictionary $dictionary): EntityMapping {
                    $transform = function (Dictionary $dictionary): PropertyMapping {
                        /** @var string $name */
                        $name = $dictionary["name"] ?? fatal_error(sprintf("%s name cannot be null", PropertyMapping::class));
                        $property = new PropertyMapping($name);
                        /** @var string|null $valueExpressionFormat */
                        $valueExpressionFormat = $dictionary["valueExpressionFormat"];
                        if ($valueExpressionFormat) {
                            $property->valueExpression = Expression::expressionWithFormat($valueExpressionFormat);
                        }
                        return $property;
                    };
                    $mapping = new EntityMapping();
                    /** @var string|null $sourceExpressionFormat */
                    $sourceExpressionFormat = $dictionary["sourceExpressionFormat"];
                    if ($sourceExpressionFormat) {
                        $mapping->sourceExpression = Expression::expressionWithFormat($sourceExpressionFormat);
                    }
                    /** @var int|null $mappingType */
                    $mappingType = $dictionary["mappingType"];
                    if ($mappingType) {
                        $mapping->mappingType = EntityMappingType::from($mappingType);
                    }
                    /** @var ArrayClass<Dictionary<mixed>>|null $attributes */
                    $attributes = $dictionary["attributes"];
                    if ($attributes) {
                        $mapping->attributeMappings = $attributes->map($transform);
                    }
                    /** @var ArrayClass<Dictionary<mixed>>|null $relationships */
                    $relationships = $dictionary["relationships"];
                    if ($relationships) {
                        $mapping->relationshipMappings = $relationships->map($transform);
                    }
                    $dictionary->removeAll(fn(mixed $value, string $key): bool => match ($key) {
                        "sourceExpressionFormat", "mappingType", "attributes", "relationships" => true,
                        default => false
                    });
                    $mapping->setValuesForKeys($dictionary);
                    return $mapping;
                });
            }
        }
    }

    /**
     * Returns the mapping model that will translate data from the source to the destination model.
     *
     * This method is a companion to the {@see ManagedObjectModel::mergedModel()} method.
     * In this case, the framework uses the version information from the models to locate the appropriate mapping model in the available bundles.
     * @param ArrayClass<Bundle>|null $bundles An array of bundles in which to search for mapping models.
     * @param ManagedObjectModel|null $sourceModel The managed object model for the source store.
     * @param ManagedObjectModel|null $destinationModel The managed object model for the destination store.
     * @return MappingModel|null Returns the mapping model to translate data from sourceModel to $destinationModel. If a suitable mapping model cannot be found, it returns null.
     * @throws Exception
     */
    public static function mappingModel(?ArrayClass $bundles, ?ManagedObjectModel $sourceModel, ?ManagedObjectModel $destinationModel): ?MappingModel
    {
        return self::mappingModelFromBundles($bundles, $sourceModel, $destinationModel);
    }

    /**
     * @throws Exception
     * @internal
     */
    public static function newMappingModel(/** @noinspection PhpUnusedParameterInspection */ ?ArrayClass $bundles, ?string $sourceHashes, ?string $destinationHashes): ?MappingModel
    {
        return null;
    }

    /** @internal */
    public static function mappingModelFromBundles(/** @noinspection PhpUnusedParameterInspection */ ?ArrayClass $bundles, ?ManagedObjectModel $sourceModel, ?ManagedObjectModel $destinationModel): ?MappingModel
    {
        // FIXME: implement mapping models from bundles
        return null;
    }

    /**
     * Returns a newly created mapping model that will migrate data from the source to the destination model.
     *
     * A model will be created only if all changes are simple enough to be able to reasonably infer a mapping (for example, removing or renaming an attribute, adding an optional attribute or relationship, or adding renaming or deleting an entity).
     * Element IDs are used to track renamed properties and entities.
     * @param ManagedObjectModel $sourceModel The source managed object model.
     * @param ManagedObjectModel $destinationModel The destination managed object model.
     * @return MappingModel A newly created mapping model to migrate data from the source to the destination model.
     * A newly created mapping model to migrate data from the source to the destination model.
     * If the mapping model cannot be created, it returns null.
     */
    public static function inferredMappingModel(ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel): MappingModel
    {
        return new MappingModelBuilder($sourceModel, $destinationModel)->newInferredMappingModel();
    }

    private function addEntityMapping(EntityMapping $entityMapping): void
    {
        if ($sourceEntityName = $entityMapping->sourceEntityName) {
            $this->sourceEntityVersionHashesByName[$sourceEntityName] = $entityMapping->sourceEntityVersionHash;
        }
        if ($destinationEntityName = $entityMapping->destinationEntityName) {
            $this->destinationEntityVersionHashesByName[$destinationEntityName] = $entityMapping->destinationEntityVersionHash;
        }
        $this->entityMappingsByName[$entityMapping->name] = $entityMapping;
    }
}
