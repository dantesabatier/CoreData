<?php

namespace Sabatier\CoreData;

use ArrayIterator;
use Countable;
use Exception;
use IteratorAggregate;
use Locale;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Traversable;
use function Sabatier\Foundation\fatal_error;
use const Sabatier\Foundation\kCFBundleNameKey;

/**
 * A programmatic representation of the model file describing your objects.
 * @implements IteratorAggregate<EntityDescription>
 */
final class ManagedObjectModel extends ObjectClass implements IteratorAggregate, Countable
{
    /** @var Dictionary<ArrayClass<EntityDescription>> */
    protected Dictionary $entitiesByConfigurationName {
        get => $this->entitiesByConfigurationName ??= new Dictionary();
    }
    /** @var ArrayClass<EntityDescription> $entities The entities in the model. Setting the entities for an object model raises an exception if the object model has been used by an object graph manager. */
    public ArrayClass $entities {
        get => $this->entitiesByName->values;
        set {
            $this->throwIfNotEditable();
            $this->entitiesByName->removeAll();
            $entities = $this->flatten($value);
            /** @var EntityDescription $entity */
            foreach ($entities as $entity) {
                $this->addEntity($entity);
            }
        }
    }
    /** @var Dictionary<EntityDescription> The entities of the model, keyed by name. */
    private(set) Dictionary $entitiesByName {
        get => $this->entitiesByName ??= new Dictionary();
    }
    /** @var Dictionary<FetchRequest> A dictionary of the receiver's fetch request templates, keyed by name. */
    protected(set) Dictionary $fetchRequestTemplatesByName {
        get => $this->fetchRequestTemplatesByName ??= new Dictionary();
    }
    /** @var ArrayClass<string> $configurations All the available configuration names of the model. */
    public ArrayClass $configurations {
        get => $this->entitiesByConfigurationName->keys;
    }
    /** @var Dictionary<string> The localization dictionary of the model. */
    public Dictionary $localizationDictionary {
        get => $this->localizationDictionary ??= new Dictionary();
    }
    /** @var Dictionary<string> A dictionary of the version hashes for the entities in the model, keyed by entity name. The dictionary of version hash information is used by Core Data to determine schema compatibility. */
    private(set) Dictionary $entityVersionHashesByName {
        get => $this->entityVersionHashesByName ??= new Dictionary();
    }
    /** @var string The Base64-encoded 128-bit model version hash. */
    public string $versionChecksum {
        get => $this->versionHash;
    }
    /** @var Set<string> The set of developer-defined version identifiers for the model. Merged models return the combined collection of identifiers. The Core Data framework does not give models a default identifier, nor does it depend on this value at runtime. For models created in Xcode, you set this value in the model inspector. This value is meant to be used as a debugging hint to help you determine the models that were combined to create a merged model. */
    public Set $versionIdentifiers {
        get => $this->versionIdentifiers ??= new Set();
    }
    /** @internal */
    private(set) string $versionHash {
        get => $this->versionHash ??= KeyedArchiver::archivedData($this->entityVersionHashesByName);
    }
    /** @internal */
    private(set) bool $isEditable = true;
    /** @internal */
    private(set) bool $isInUse = false;
    /** @internal */
    private(set) bool $isImmutable = false;
    /** @var list<string> */
    private const array archivableModelKeys = ["entities", "fetchRequestTemplatesByName", "entitiesByConfigurationName", "localizationDictionary", "versionIdentifiers"];
    /** @var ArrayClass<string> */
    private ArrayClass $archivableModelKeys {
        get => $this->archivableModelKeys ??= new ArrayClass(self::archivableModelKeys);
    }

    /**
     * Initializes the managed object model using the model file at the specified URL.
     * @param URL|null $url A URL object specifying the location of a model file.
     */
    public function __construct(?URL $url = null)
    {
        if ($url && ($data = FileManager::default()->contents($url->path))) {
            /** @var ManagedObjectModel $unarchivedModel */
            $unarchivedModel = KeyedUnarchiver::unarchiveTopLevelObjectWithData($data);
            $this->setValuesForKeys($unarchivedModel->dictionaryWithValues($this->archivableModelKeys));
            $bundle = Bundle::bundleWithURL($url->deletingLastPathComponent()->deletingLastPathComponent());
            $name = $url->deletingPathExtension()->lastPathComponent;
            $poURL = $bundle->url("{$name}Model", "po", null, Locale::getPrimaryLanguage(Locale::getDefault()));
            if ($poURL && ($content = FileManager::default()->contents($poURL->path))) {
                $this->localizationDictionary = new StringsFileParser($content)->dictionary;
            }
            $this->isEditable = false;
        }
    }

    /**
     * @internal
     */
    public static function newModel(string $data): ManagedObjectModel
    {
        /** @var ManagedObjectModel $unarchivedModel */
        $unarchivedModel = KeyedUnarchiver::unarchiveTopLevelObjectWithData($data);
        $model = new ManagedObjectModel();
        $model->setValuesForKeys($unarchivedModel->dictionaryWithValues($model->archivableModelKeys));
        $model->isImmutable = true;
        $model->isEditable = false;
        return $model;
    }

    /**
     * Returns a merged model from a specified array for the version information in provided metadata.
     * @param ArrayClass<Bundle> $bundles An array of bundles.
     * @param Dictionary<mixed> $metadata A dictionary containing version information from the metadata for a persistent store.
     * @return ManagedObjectModel|null The managed object model used to create the store for the metadata.
     * If a model cannot be created to match the version information specified by $metadata, it returns null.
     */
    public static function mergedModel(ArrayClass $bundles, Dictionary $metadata): ?ManagedObjectModel
    {
        return ManagedObjectModel::merging($bundles->compactMap(fn(Bundle $bundle): ?ManagedObjectModel => (($name = $bundle->object(kCFBundleNameKey)) && ($url = $bundle->url($name, "mom"))) ? new ManagedObjectModel($url) : null), $metadata);
    }

    /**
     * Returns, for the version information in given metadata, a model merged from a given array of models.
     *
     * This is the companion method to {@see mergedModel()}.
     * @param ArrayClass<ManagedObjectModel> $models An array of ManagedObjectModel.
     * @param Dictionary<mixed> $metadata A dictionary containing version information from the metadata for a persistent store.
     * @return ManagedObjectModel|null A merged model from $models for the version information in $metadata. If a model cannot be created to match the version information in $metadata, it returns null.
     */
    public static function merging(/** @noinspection PhpUnusedParameterInspection */ ArrayClass $models, Dictionary $metadata): ?ManagedObjectModel
    {
        if ($models->isEmpty) {
            return null;
        }
        $managedObjectModel = new ManagedObjectModel();
        $managedObjectModel->entities = $models->flatMap(fn(ManagedObjectModel $model): ArrayClass => $model->entities);
        return $managedObjectModel;
    }

    public function localizedEntityName(string $entityName): string
    {
        /** @var string */
        return $this->localizationDictionary["Entity/$entityName"] ?? $entityName;
    }

    public function localizedPropertyName(string $propertyName, string $entityName): string
    {
        /** @var string */
        return $this->localizationDictionary["$propertyName/Entity/$entityName"] ?? $this->localizationDictionary[$propertyName] ?? $propertyName;
    }

    private function throwIfNotEditable(): void
    {
        $this->isEditable ?: fatal_error("$this->debugDescription cannot be edited before initialization");
    }

    /**
     * @param ArrayClass<EntityDescription> $entities
     * @return ArrayClass<EntityDescription>
     * @internal
     */
    public function flatten(ArrayClass $entities): ArrayClass
    {
        /** @var ArrayClass<EntityDescription> $result */
        $result = new ArrayClass();
        foreach ($entities as $entity) {
            $result->append($entity);
            $result->appendContentsOf($this->flatten($entity->subentities));
        }
        return $result;
    }

    /** @internal */
    public function addEntity(EntityDescription $entity): void
    {
        if (!$this->entitiesByName[$entity->name]) {
            if (!$entity->isPersistentHistoryEntity) {
                $entityVersionHashesByName = $this->entityVersionHashesByName;
                $entityVersionHashesByName[$entity->name] = $entity->versionHash;
            }
            $managedObjectClassName = $entity->managedObjectClassName;
            if (is_subclass_of($managedObjectClassName, ManagedObject::class)) {
                $managedObjectClassName::setStaticAssociatedValueForKey($entity, "entity");
            }
            $this->entitiesByName[$entity->name] = $entity;
            $entity->managedObjectModel = $this;
            $entity->flattenProperties();
        }
    }

    /** @internal */
    public function entity(string $named): ?EntityDescription
    {
        $entity = $this->entitiesByName[$named];
        if (!$entity) {
            return null;
        }
        if ($entity->isRootEntity) {
            return $entity;
        }
        return $entity->rootEntity;
    }

    /**
     * Returns the entities of the model for a specified configuration.
     * @param string|null $configuration The name of a configuration in the receiver.
     * @return ArrayClass<EntityDescription>|null An array containing the entities of the receiver for the configuration specified by configuration.
     */
    public function entities(?string $configuration): ?ArrayClass
    {
        return $configuration ? $this->entitiesByConfigurationName[$configuration] : null;
    }

    /**
     * Associates the specified entities with the model using the given configuration name.
     *
     * This method raises an exception if the receiver has been used by an object graph manager.
     * @param ArrayClass<EntityDescription> $entities An array of EntityDescription.
     * @param string $configuration A name for the configuration.
     */
    public function setEntities(ArrayClass $entities, string $configuration): void
    {
        $this->throwIfNotEditable();
        $this->entitiesByConfigurationName[$configuration] = $entities;
    }

    /**
     * Returns the fetch request with a specified name.
     * @param string $name A string containing the name of a fetch request template.
     * @return FetchRequest|null The fetch request named $name.
     */
    public function fetchRequestTemplate(string $name): ?FetchRequest
    {
        return $this->fetchRequestTemplatesByName[$name];
    }

    /**
     * Returns a copy of the fetch request template with the variables substituted by values from the substitutions' dictionary.
     *
     * The $$substitutionVariables dictionary must provide values for all the variables.
     * This method provides the usual way to bind an “abstractly” defined fetch request template to a concrete fetch.
     * @param string $name A string containing the name of a fetch request template.
     * @param Dictionary<mixed> $substitutionVariables A dictionary containing key-value pairs where the keys are the names of variables specified in the template;
     * the corresponding values are substituted before the fetch request is returned.
     * The dictionary must provide values for all the variables in the template.
     * @return FetchRequest|null A copy of the fetch request template with the variables substituted by values from variables.
     */
    public function fetchRequestFromTemplate(string $name, Dictionary $substitutionVariables): ?FetchRequest
    {
        $fetchRequest = $this->fetchRequestTemplate($name);
        if (!$substitutionVariables->isEmpty && $fetchRequest && (($predicate = $fetchRequest->predicate))) {
            /** @var FetchRequest */
            return clone($fetchRequest, [
                "predicate" => $predicate->withSubstitutionVariables($substitutionVariables)
            ]);
        }
        return $fetchRequest;
    }

    /**
     * Associates the specified fetch request with the receiver using the given name.
     *
     * This method raises an exception if the receiver has been used by an object graph manager.
     * @param FetchRequest $fetchRequest A fetch request, typically containing predicates with variables for substitution.
     * @param string $name A string that specifies the name of the fetch request template.
     */
    public function setFetchRequestTemplate(FetchRequest $fetchRequest, string $name): void
    {
        $this->throwIfNotEditable();
        $this->fetchRequestTemplatesByName[$name] = $fetchRequest;
    }

    /**
     * Returns a Boolean value that indicates whether a given configuration in the model is compatible with given metadata from a persistent store.
     *
     * This method compares the version information in the store metadata with the entity versions of a given configuration.
     * For information on specific differences, use {@see entityVersionHashesByName} and perform an entity-by-entity comparison.
     * @param string|null $configuration The name of a configuration in the receiver. Pass null to specify no configuration.
     * @param Dictionary<mixed> $metadata Metadata for a persistent store.
     * @return bool true if the configuration in the receiver specified by configuration is compatible with the store metadata given by metadata, otherwise false.
     */
    public function isConfigurationCompatibleWithStoreMetadata(?string $configuration, Dictionary $metadata): bool
    {
        if ($metadata[StoreModelVersionHashesKey]) {
            return KeyedArchiver::archivedData($this->entities($configuration)?->reduce(new Dictionary(),
                    /**
                     * @param Dictionary<string> $result
                     * @param EntityDescription $entity
                     * @return Dictionary<string>
                     */
                    function (Dictionary &$result, EntityDescription $entity): Dictionary {
                        $result[$entity->name] = $entity->versionHash;
                        return $result;
                    }) ?? $this->entityVersionHashesByName) === $metadata[StoreModelVersionHashesKey];
        }
        return false;
    }

    /**
     * @throws Exception
     * @internal
     */
    public function entityVersionHashesByNameInStyle(VersionHashStyle $style): string
    {
        return KeyedArchiver::archivedData($this->entitiesByName->compactMapValues(fn(EntityDescription $entity): ?string => $entity->isPersistentHistoryEntity ? null : $entity->versionHashInStyle($style)));
    }

    #[Override]
    public function count(): int
    {
        return $this->entitiesByName->count;
    }

    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entities->array);
    }
}
