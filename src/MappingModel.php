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
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\URL;

/**
 * A model instance that specifies how to map a model from a source to a destination managed object model.
 */
final class MappingModel extends ObjectClass
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
    /** @var ManagedObjectModel|null The managed object model the mapping model maps from. A mapping model carries the two models it was authored against, so that loading one yields the pair its entity mappings were written for. */
    public ?ManagedObjectModel $sourceModel = null;
    /** @var ManagedObjectModel|null The managed object model the mapping model maps to. */
    public ?ManagedObjectModel $destinationModel = null;
    /** @var list<string> */
    private const array archivableMappingModelKeys = ["entityMappings", "sourceModel", "destinationModel"];
    /** @var ArrayClass<string> */
    private ArrayClass $archivableMappingModelKeys {
        get => $this->archivableMappingModelKeys ??= new ArrayClass(self::archivableMappingModelKeys);
    }

    /**
     * Returns a mapping model initialized from a given URL.
     * @param URL|null $url The location of an archived mapping model.
     * @throws Exception
     */
    public function __construct(?URL $url = null)
    {
        if ($url && ($data = FileManager::default()->contents($url->path))) {
            /** @var MappingModel $unarchivedMappingModel */
            $unarchivedMappingModel = KeyedUnarchiver::unarchiveTopLevelObjectWithData($data);
            $this->setValuesForKeys($unarchivedMappingModel->dictionaryWithValues($this->archivableMappingModelKeys));
            // Unarchiving yields the models as they were written: still editable, and with the indexes addEntity() maintains carried over from the archive rather than rebuilt.
            $this->sourceModel = self::newModel($this->sourceModel);
            $this->destinationModel = self::newModel($this->destinationModel);
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
     * Returns the mapping model in the given bundles whose entity version hashes match the archived
     * hashes of a source and destination model.
     *
     * Mapping models are located by version information rather than by file name: a mapping model
     * declares the hashes of the entities it was authored against, which is what identifies the pair
     * of model versions it applies to.
     * @param ArrayClass<Bundle>|null $bundles An array of bundles in which to search for mapping models.
     * @param string|null $sourceHashes The archived entity version hashes of the source model.
     * @param string|null $destinationHashes The archived entity version hashes of the destination model.
     * @throws Exception
     * @internal
     */
    public static function newMappingModel(?ArrayClass $bundles, ?string $sourceHashes, ?string $destinationHashes): ?MappingModel
    {
        if (!$sourceHashes || !$destinationHashes) {
            return null;
        }
        foreach ($bundles ?? Bundle::allBundles() as $bundle) {
            foreach ($bundle->urls(MappingModelFileExtension) ?? new ArrayClass() as $url) {
                $mappingModel = new MappingModel($url);
                if (KeyedArchiver::archivedData($mappingModel->sourceEntityVersionHashesByName) === $sourceHashes &&
                    KeyedArchiver::archivedData($mappingModel->destinationEntityVersionHashesByName) === $destinationHashes) {
                    return $mappingModel;
                }
                if (self::$migrationDebugLevel) {
                    error_log("CoreData: Mapping model at $url does not map the requested model versions");
                }
            }
        }
        return null;
    }

    /** @throws Exception
     * @internal
     */
    public static function mappingModelFromBundles(?ArrayClass $bundles, ?ManagedObjectModel $sourceModel, ?ManagedObjectModel $destinationModel): ?MappingModel
    {
        if (!$sourceModel || !$destinationModel) {
            return null;
        }
        return self::newMappingModel($bundles, KeyedArchiver::archivedData($sourceModel->entityVersionHashesByName), KeyedArchiver::archivedData($destinationModel->entityVersionHashesByName));
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

    /**
     * Rebuilds a model that arrived as part of an archived mapping model, so it is indistinguishable
     * from one read from its own file.
     */
    private static function newModel(?ManagedObjectModel $model): ?ManagedObjectModel
    {
        return $model ? ManagedObjectModel::newModel(KeyedArchiver::archivedData($model)) : null;
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
