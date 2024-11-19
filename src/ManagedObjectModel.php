<?php

namespace Sabatier\CoreData;

use ArrayIterator;
use Countable;
use Exception;
use IteratorAggregate;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\PropertyListSerialization;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Traversable;
use function Sabatier\Foundation\fatal_error;
use const Sabatier\Foundation\kCFBundleNameKey;

/**
 * A programmatic representation of the model file describing your objects.
 * @implements IteratorAggregate<EntityDescription>
 */
class ManagedObjectModel extends ObjectClass implements IteratorAggregate, Countable
{
    /** @var Dictionary<ArrayClass<EntityDescription>> */
    private Dictionary $entitiesByConfigurationName;
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
    private(set) Dictionary $entitiesByName;
    /** @var Dictionary<FetchRequest> A dictionary of the receiver's fetch request templates, keyed by name. */
    private(set) Dictionary $fetchRequestTemplatesByName;
    /** @var ArrayClass<string> $configurations All the available configuration names of the model. */
    public ArrayClass $configurations {
        get => $this->entitiesByConfigurationName->keys;
    }
    /** @var Dictionary<string>|null The localization dictionary of the model. */
    public ?Dictionary $localizationDictionary = null;
    /** @var Dictionary<string> A dictionary of the version hashes for the entities in the model, keyed by entity name. The dictionary of version hash information is used by Core Data to determine schema compatibility. */
    private(set) Dictionary $entityVersionHashesByName;
    /** @var Set<string> The set of developer-defined version identifiers for the model. Merged models return the combined collection of identifiers. The Core Data framework does not give models a default identifier, nor does it depend on this value at runtime. For models created in Xcode, you set this value in the model inspector. This value is meant to be used as a debugging hint to help you determine the models that were combined to create a merged model. */
    public Set $versionIdentifiers;
    /** @internal */
    private(set) string $versionHash {
        get => $this->versionHash ??= KeyedArchiver::archivedData($this->entityVersionHashesByName);
    }
    /** @internal */
    public bool $isEditable = true;
    /** @internal */
    public bool $isInUse = false;
    /** @internal */
    private(set) bool $isImmutable = false;

    /**
     * Initializes the managed object model using the model file at the specified URL.
     * @param URL|null $url A URL object specifying the location of a model file.
     */
    public function __construct(?URL $url = null)
    {
        $this->versionIdentifiers = new Set();
        $this->entitiesByName = new Dictionary();
        $this->entitiesByConfigurationName = new Dictionary();
        $this->fetchRequestTemplatesByName = new Dictionary();
        $this->entityVersionHashesByName = new Dictionary();
        if ($url) {
            $propertyList = PropertyListSerialization::propertyListWithURL($url);
            if ($propertyList instanceof Dictionary) {
                $this->recreate($propertyList);
            }
        }
    }

    /**
     * @param Dictionary $dictionary
     * @param EntityDescription|null $superentity
     * @param ArrayClass<Dictionary<mixed>>|null $compositeTypes
     * @return EntityDescription
     */
    private function newEntity(Dictionary $dictionary, ?EntityDescription $superentity = null, ?ArrayClass $compositeTypes = null): EntityDescription
    {
        /** @var string $name */
        $name = $dictionary["name"] ?? fatal_error("Entity name cannot be null");
        $entity = $this->entitiesByName[$name];
        if (!$entity instanceof EntityDescription) {
            $entity = new EntityDescription();
            $entity->name = $name;
            $entity->superentity = $superentity;
            /** @var class-string<ManagedObject>|null $managedObjectClassName */
            $managedObjectClassName = $dictionary["managedObjectClassName"];
            if ($managedObjectClassName) {
                $entity->managedObjectClassName = $managedObjectClassName;
            }
            if ($managedObjectClassName = $entity->managedObjectClassName) {
                /** @noinspection PhpUndefinedVariableInspection */
                $managedObjectClassName::$staticAssociatedValues[$managedObjectClassName]["entity"] = $entity;
            }
            if ($isAbstract = $dictionary["isAbstract"]) {
                $entity->isAbstract = $isAbstract;
            }
            if ($renamingIdentifier = $dictionary["renamingIdentifier"]) {
                $entity->renamingIdentifier = $renamingIdentifier;
            }
            $entity->versionHashModifier = $dictionary["versionHashModifier"];
            /** @var ArrayClass<PropertyDescription> $properties */
            $properties = new ArrayClass();
            /** @var ArrayClass<Dictionary>|null $attributes */
            $attributes = $dictionary["attributes"];
            if ($attributes) {
                $properties->appendContentsOf($attributes->map(function (Dictionary $description) use ($entity, $compositeTypes): AttributeDescription {
                    /** @var string|null $derivationExpressionFormat */
                    $derivationExpressionFormat = $description["derivationExpressionFormat"];
                    $description->removeAll(fn(mixed $value, string $key): bool => match ($key) {
                        "isDefaultValueBounded", "isMinValueBounded", "isMaxValueBounded", "derivationExpressionFormat" => true,
                        default => false
                    });
                    if ($derivationExpressionFormat) {
                        $derivedAttribute = new DerivedAttributeDescription();
                        $derivedAttribute->entity = $entity;
                        $derivedAttribute->derivationExpression = Expression::expressionWithFormat(trim($derivationExpressionFormat));
                        $derivedAttribute->setValuesForKeys($description);
                        return $derivedAttribute;
                    }
                    if ($description["type"] === AttributeType::compositeAttributeType->value) {
                        $compositeType = $compositeTypes?->first(fn(Dictionary $compositeType): bool => $compositeType["name"] === $description["attributeValueClassName"]);
                        if ($compositeType) {
                            /** @var ArrayClass<Dictionary<mixed>> $elements */
                            $elements = $compositeType["elements"] ?? fatal_error(sprintf("%s elements cannot be null", CompositeAttributeDescription::class));
                            $description->removeAll(fn(mixed $value, string $key): bool => match ($key) {
                                "attributeValueClassName", "elements" => true,
                                default => false
                            });
                            $compositeAttribute = new CompositeAttributeDescription();
                            $compositeAttribute->entity = $entity;
                            $compositeAttribute->elements = $elements->map(function (Dictionary $element) use ($entity, $compositeAttribute): AttributeDescription {
                                $attribute = new AttributeDescription();
                                $attribute->entity = $entity;
                                $attribute->superCompositeAttribute = $compositeAttribute;
                                $attribute->setValuesForKeys($element);
                                return $attribute;
                            });
                            $compositeAttribute->setValuesForKeys($description);
                            return $compositeAttribute;
                        }
                    }
                    $attribute = new AttributeDescription();
                    $attribute->entity = $entity;
                    $attribute->setValuesForKeys($description);
                    return $attribute;
                }));
            }
            /** @var ArrayClass<Dictionary>|null $relationships */
            $relationships = $dictionary["relationships"];
            if ($relationships) {
                $properties->appendContentsOf($relationships->map(function (Dictionary $description) use ($entity): RelationshipDescription {
                    $description->removeAll(fn(mixed $value, string $key): bool => match ($key) {
                        "isMinValueBounded", "isMaxValueBounded", "isMinCountBounded", "isMaxCountBounded" => true,
                        default => false
                    });
                    $instance = new RelationshipDescription();
                    $instance->entity = $entity;
                    $instance->setValuesForKeys($description);
                    return $instance;
                }));
            }
            /** @var ArrayClass<Dictionary>|null $fetchedProperties */
            $fetchedProperties = $dictionary["fetchedProperties"];
            if ($fetchedProperties) {
                $properties->appendContentsOf($fetchedProperties->map(function (Dictionary $description) use ($entity): FetchedPropertyDescription {
                    /** @var string $name */
                    $name = $description["name"] ?? fatal_error(sprintf("%s name cannot be null", FetchedPropertyDescription::class));
                    /** @var string $fetchRequestEntityName */
                    $fetchRequestEntityName = $description["fetchRequestEntityName"] ?? fatal_error(sprintf("%s entity name cannot be null (%s:%s)", FetchRequest::class, $entity->name, $name));
                    $fetchRequest = new FetchRequest($fetchRequestEntityName);
                    /** @var string|null $fetchRequestPredicateFormat */
                    $fetchRequestPredicateFormat = $description["fetchRequestPredicateFormat"];
                    if ($fetchRequestPredicateFormat) {
                        $fetchRequest->predicate = Predicate::format($fetchRequestPredicateFormat);
                    }
                    $description->removeAll(fn(mixed $value, string $key): bool => match ($key) {
                        "name", "fetchRequestEntityName", "fetchRequestPredicateFormat" => true,
                        default => false
                    });
                    $fetchRequest->setValuesForKeys($description);
                    $instance = new FetchedPropertyDescription();
                    $instance->entity = $entity;
                    $instance->name = $name;
                    $instance->fetchRequest = $fetchRequest;
                    return $instance;
                }));
            }
            $entity->properties = $properties;
            /** @var Dictionary|null $superentity */
            $superentity = $dictionary["superentity"];
            if ($superentity) {
                $entity->superentity = $this->newEntity($superentity, $compositeTypes);
            }
            /** @var ArrayClass<Dictionary>|null $subentities */
            $subentities = $dictionary["subentities"];
            if ($subentities) {
                $entity->subentities = $subentities->map(fn(Dictionary $description): EntityDescription => $this->newEntity($description, $entity, $compositeTypes));
            }
            /** @var ArrayClass<ArrayClass<string>> $uniquenessConstraints */
            $uniquenessConstraints = $dictionary["uniquenessConstraints"] ?? new ArrayClass();
            $entity->uniquenessConstraints = $uniquenessConstraints;
            /** @var ArrayClass<Dictionary>|null $indexes */
            $indexes = $dictionary["indexes"];
            if ($indexes) {
                $entity->indexes = $indexes->map(function (Dictionary $dictionary) use ($entity): FetchIndexDescription {
                    /** @var string $name */
                    $name = $dictionary["name"] ?? fatal_error(sprintf("%s name cannot be null", FetchIndexDescription::class));
                    $index = new FetchIndexDescription($name);
                    $index->entity = $entity;
                    /** @var string|null $partialIndexPredicateFormat */
                    $partialIndexPredicateFormat = $dictionary["partialIndexPredicateFormat"];
                    if ($partialIndexPredicateFormat) {
                        $index->partialIndexPredicate = Predicate::format($partialIndexPredicateFormat);
                    }
                    /** @var ArrayClass<Dictionary> $elements */
                    $elements = $dictionary["elements"] ?? new ArrayClass();
                    $index->elements = $elements->map(function (Dictionary $dictionary) use ($name, $entity): FetchIndexElementDescription {
                        /** @var string|null $expressionFormat */
                        $expressionFormat = $dictionary["expressionFormat"];
                        if ($expressionFormat) {
                            $propertyDescription = new ExpressionDescription();
                            $propertyDescription->name = $name;
                            $propertyDescription->expression = Expression::expressionWithFormat($expressionFormat);
                            $propertyDescription->resultType = AttributeType::from($dictionary["expressionResultType"] ?? AttributeType::undefined->value);
                            $dictionary->removeAll(fn(mixed $value, string $key): bool => match ($key) {
                                "expressionFormat", "expressionResultType" => true,
                                default => false
                            });
                        } else {
                            /** @var string $propertyName */
                            $propertyName = $dictionary["propertyName"] ?? fatal_error(sprintf("%s name cannot be null", FetchIndexElementDescription::class));
                            if (!($propertyDescription = $entity->propertiesByName[$propertyName])) {
                                $superentity = $entity->superentity;
                                while ($superentity) {
                                    if ($propertyDescription = $superentity->propertiesByName[$propertyName]) {
                                        break;
                                    }
                                    $superentity = $superentity->superentity;
                                }
                            }
                        }
                        $dictionary->removeAll(fn(mixed $value, string $key): bool => match ($key) {
                            "name", "partialIndexPredicateFormat", "elements", "propertyName" => true,
                            default => false
                        });
                        $propertyDescription instanceof PropertyDescription ?: fatal_error(sprintf("%s property cannot be null", FetchIndexElementDescription::class));
                        $element = new FetchIndexElementDescription($propertyDescription);
                        $element->setValuesForKeys($dictionary);
                        return $element;
                    });
                    return $index;
                });
            }
            $entity->userInfo = $dictionary["userInfo"];
            $this->entitiesByName[$name] = $entity;
        }
        return $entity;
    }

    private function newFetchRequest(Dictionary $dictionary): ?FetchRequest
    {
        /** @var string|null $fetchEntityName */
        $fetchEntityName = $dictionary["fetchEntityName"];
        if ($fetchEntityName && ($entity = $this->entitiesByName[$fetchEntityName])) {
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $entity;
            /** @var string|null $predicateString */
            $predicateString = $dictionary["predicateString"];
            if ($predicateString) {
                $fetchRequest->predicate = Predicate::format($predicateString);
            }
            /** @var int|null $fetchResultType */
            $fetchResultType = $dictionary["fetchResultType"];
            if (($fetchResultType !== null) && $fetchResultType = FetchRequestResultType::tryFrom($fetchResultType)) {
                $fetchRequest->resultType = $fetchResultType;
            }
            return $fetchRequest;
        }
        return null;
    }

    private function recreate(Dictionary $dictionary): void
    {
        /** @var ArrayClass<Dictionary<mixed>>|null $compositeTypes */
        $compositeTypes = $dictionary["compositeTypes"];
        /** @var ArrayClass<Dictionary>|null $entities */
        $entities = $dictionary["entities"];
        if ($entities) {
            $this->entities = $entities->map(fn(Dictionary $dictionary): EntityDescription => $this->newEntity($dictionary, $compositeTypes));
        }
        /** @var ArrayClass<Dictionary>|null $fetchRequestTemplates */
        $fetchRequestTemplates = $dictionary["fetchRequests"];
        if ($fetchRequestTemplates) {
            foreach ($fetchRequestTemplates as $fetchRequestTemplate) {
                /** @var string|null $name */
                $name = $fetchRequestTemplate["name"];
                if ($name) {
                    $fetchRequest = $this->newFetchRequest($fetchRequestTemplate);
                    if ($fetchRequest) {
                        $this->setFetchRequestTemplate($fetchRequest, $name);
                    }
                }
            }
        }
        /** @var ArrayClass<Dictionary>|null $configurations */
        $configurations = $dictionary["configurations"];
        if ($configurations) {
            foreach ($configurations as $configuration) {
                /** @var string|null $configurationName */
                $configurationName = $configuration["name"];
                /** @var ArrayClass<string>|null $entityNames */
                $entityNames = $configuration["entities"];
                if ($configurationName && $entityNames) {
                    /** @psalm-suppress InvalidArgument */
                    $this->setEntities($entityNames->compactMap(fn(string $entityName): ?EntityDescription => $this->entitiesByName[$entityName]), $configurationName);
                }
            }
        }
    }

    /**
     * @throws Exception
     * @internal
     */
    public static function newModel(string $data): ?ManagedObjectModel
    {
        /** @var Dictionary<string> $dictionary */
        $dictionary = KeyedUnarchiver::unarchiveTopLevelObjectWithData($data);
        $model = new ManagedObjectModel();
        $model->isImmutable = true;
        $model->recreate($dictionary);
        return $model;
    }

    /**
     * Returns a merged model from a specified array for the version information in provided metadata.
     * @param ArrayClass<Bundle> $bundles An array of bundles.
     * @param Dictionary $metadata A dictionary containing version information from the metadata for a persistent store.
     * @return ManagedObjectModel|null The managed object model used to create the store for the metadata.
     * If a model cannot be created to match the version information specified by metadata, returns nil.
     */
    public static function mergedModel(ArrayClass $bundles, Dictionary $metadata): ?ManagedObjectModel
    {
        /** @psalm-suppress InvalidArgument */
        return static::merging($bundles->compactMap(fn(Bundle $bundle): ?ManagedObjectModel => (($name = $bundle->object(kCFBundleNameKey)) && ($url = $bundle->url($name, "plist"))) ? new ManagedObjectModel($url) : null), $metadata);
    }

    /**
     * Returns, for the version information in given metadata, a model merged from a given array of models.
     *
     * This is the companion method to {@see mergedModel()}.
     * @param ArrayClass<ManagedObjectModel> $models An array of instances of ManagedObjectModel.
     * @param Dictionary $metadata A dictionary containing version information from the metadata for a persistent store.
     * @return ManagedObjectModel|null A merged model from models for the version information in metadata. If a model cannot be created to match the version information in metadata, returns nil.
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

    private function throwIfNotEditable(): void
    {
        $this->isEditable ?: fatal_error();
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
     * @param ArrayClass<EntityDescription> $entities An array of instances of EntityDescription.
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
     * @return FetchRequest|null The fetch request named name.
     */
    public function fetchRequestTemplate(string $name): ?FetchRequest
    {
        return $this->fetchRequestTemplatesByName[$name];
    }

    /**
     * Returns a copy of the fetch request template with the variables substituted by values from the substitutions' dictionary.
     *
     * The variables dictionary must provide values for all the variables.
     * This method provides the usual way to bind an “abstractly” defined fetch request template to a concrete fetch.
     * @param string $name A string containing the name of a fetch request template.
     * @param Dictionary $substitutionVariables A dictionary containing key-value pairs where the keys are the names of variables specified in the template;
     * the corresponding values are substituted before the fetch request is returned.
     * The dictionary must provide values for all the variables in the template.
     * @return FetchRequest|null A copy of the fetch request template with the variables substituted by values from variables.
     */
    public function fetchRequestFromTemplate(string $name, Dictionary $substitutionVariables): ?FetchRequest
    {
        $fetchRequest = $this->fetchRequestTemplate($name);
        if (!$substitutionVariables->isEmpty && $fetchRequest && (($predicate = $fetchRequest->predicate))) {
            $fetchRequest = clone $fetchRequest;
            $fetchRequest->predicate = $predicate->withSubstitutionVariables($substitutionVariables);
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
     * @param string|null $configuration The name of a configuration in the receiver. Pass nil to specify no configuration.
     * @param Dictionary $metadata Metadata for a persistent store.
     * @return bool true if the configuration in the receiver specified by configuration is compatible with the store metadata given by metadata, otherwise false.
     */
    public function isConfigurationCompatibleWithStoreMetadata(?string $configuration, Dictionary $metadata): bool
    {
        if ($metadata[StoreModelVersionHashesKey]) {
            return KeyedArchiver::archivedData($this->entities($configuration)?->reduce(new Dictionary(), function (Dictionary &$result, EntityDescription $entity): Dictionary {
                    /** @psalm-suppress InvalidArgument */
                    $result[$entity->name] = $entity->versionHash;
                    return $result;
                }) ?? $this->entityVersionHashesByName) === $metadata[StoreModelVersionHashesKey];
        }
        return true;
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

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        /** @var Dictionary<ArrayClass<Dictionary>> $dictionary */
        $dictionary = new Dictionary();
        $dictionary["entities"] = $this->entitiesByName->filter(fn(EntityDescription $entity): bool => !$entity->isPersistentHistoryEntity && $entity->isRootEntity)->map(fn(EntityDescription $entity): Dictionary => $entity->jsonSerialize());
        $dictionary["fetchRequests"] = $this->fetchRequestTemplatesByName->mapValues(fn(FetchRequest $fetchRequest, string $templateName): Dictionary => new Dictionary(["name" => $templateName, "entityName" => $fetchRequest->entityName, "predicateString" => $fetchRequest->predicate->predicateFormat, "resultType" => $fetchRequest->resultType !== FetchRequestResultType::managedObjectResultType ? $fetchRequest->resultType->value : null]))->values;
        return $dictionary;
    }
}
