<?php

namespace Sabatier\CoreData;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;
use Traversable;

/**
 * A description of an entity in Core Data.
 *
 * @implements IteratorAggregate<PropertyDescription>
 * @property ArrayClass<EntityDescription> $subentities An array containing the sub-entities of the receiver.
 * @property ArrayClass<PropertyDescription> $properties An array containing the properties of the receiver. The elements in the array are instances of {@see AttributeDescription}, {@see RelationshipDescription}, and/or {@see FetchedPropertyDescription}.
 * @property ArrayClass<FetchIndexDescription> $indexes An array of fetch index descriptions for the entity. This value doesn't form part of the entity's version hash, and stores that don't natively support indexing may ignore it. Set indexes last in a model. Changing an entity hierarchy in any way that affects the validity of indexes drops all existing indexes for entities in that hierarchy, such as adding or removing superentities or subentities, or adding and removing properties anywhere in the hierarchy.
 */
class EntityDescription extends ObjectClass implements IteratorAggregate, Countable
{
    /** @var string The entity name of the receiver. */
    public string $name = UnknownName;
    /** @var ManagedObjectModel The managed object model with which the receiver is associated. */
    public ManagedObjectModel $managedObjectModel;
    /** @var class-string<ManagedObject>|null $managedObjectClassName The name of the class that represents the receiver's entity. The class specified by name must be {@see ManagedObject} or a subclass of ManagedObject. */
    public ?string $managedObjectClassName = null;
    /** @var string The renaming identifier for the receiver. The renaming identifier is used to resolve naming conflicts between models. When creating a mapping model between two managed object models, a source entity and a destination entity that share the same identifier indicate that an entity mapping should be configured to migrate from the source to the destination. If you do not set this value, the identifier will return the entity's name. */
    public string $renamingIdentifier;
    /** @var bool A Boolean value that indicates whether the receiver represents an abstract entity. An abstract entity might be Shape, with concrete sub-entities such as Rectangle, Triangle, and Circle. */
    public bool $isAbstract = false;
    /** @var Dictionary|null The user info dictionary of the receiver. */
    public ?Dictionary $userInfo = null;
    /** @var Dictionary<EntityDescription> A dictionary containing the receiver's sub-entities. */
    public readonly Dictionary $subentitiesByName;
    /** @var EntityDescription|null The super-entity of the receiver. */
    public ?EntityDescription $superentity = null;
    /** @var Dictionary<PropertyDescription> A dictionary containing the properties of the receiver. */
    public readonly Dictionary $propertiesByName;
    /** @var Dictionary<AttributeDescription> The attributes of the receiver in a dictionary. The keys in the dictionary are the attribute names and the values are instances of {@see AttributeDescription}. */
    public readonly Dictionary $attributesByName;
    /** @var Dictionary<RelationshipDescription> The relationships of the receiver in a dictionary. The keys in the dictionary are the relationship names and the values are instances of {@see RelationshipDescription}. */
    public readonly Dictionary $relationshipsByName;
    /** @var ArrayClass<FetchIndexDescription> $indexes */
    protected ArrayClass $indexes;
    /** @var ArrayClass<ArrayClass<AttributeDescription|string>> An array of arrays that contains one or more attributes with a value that must be unique over the instances of that entity. Each inner array contains one or more {@see AttributeDescription} objects or strings that contain the names of attributes on the entity. This value forms part of the entity's version hash. Stores that don't support uniqueness constraints must refuse to initialize when receiving a model that contains such constraints. Uniqueness constraint violations can be computationally expensive to handle. The recommendation is to use only one uniqueness constraint per entity hierarchy, although subentites may extend a superentity's constraint. */
    public ArrayClass $uniquenessConstraints;
    /** @var string The version hash is used to uniquely identify an entity based on the collection and configuration of properties for the entity. The version hash uses only values which affect the persistence of data and the user-defined {@see versionHashModifier} value. (The values which affect persistence are: the name of the entity, the version hash of the superentity (if present), if the entity is abstract, and all the version hashes for the properties.) This value is stored as part of the version information in the metadata for stores which use this entity, as well as a definition of an entity involved in an {@see EntityMapping} object. */
    public readonly string $versionHash;
    /** @var string|null The version hash modifier for the receiver. This value is included in the version hash for the entity. You use it to mark or denote an entity as being a different “version” than another even if all the values which affect persistence are equal. (Such a difference is important in cases where, for example, the structure of an entity is unchanged but the format or content of data has changed.) */
    public ?string $versionHashModifier = null;
    /** @internal */
    public bool $isFlattened = false;
    /** @internal */
    public bool $isEditable = true;
    /** @internal */
    public bool $isPersistentHistoryEntity = false;
    /** @internal */
    public readonly ?EntityDescription $rootEntity;
    /** @internal */
    public readonly bool $isRootEntity;

    public function __construct()
    {
        unset($this->versionHash);
        unset($this->renamingIdentifier);
        unset($this->attributesByName);
        unset($this->relationshipsByName);
        $this->subentitiesByName = new Dictionary();
        $this->propertiesByName = new Dictionary();
        $this->indexes = new ArrayClass();
        $this->uniquenessConstraints = new ArrayClass();
    }

    public function __get(string $name)
    {
        if ($name == "subentities") {
            return $this->subentitiesByName->values;
        } elseif ($name == "indexes") {
            return $this->$name;
        } elseif ($name == "properties") {
            return $this->propertiesByName->values;
        } elseif ($name == "versionHash") {
            $this->$name = $this->versionHashInStyle(VersionHashStyle::default);
            return $this->$name;
        } elseif ($name == "renamingIdentifier") {
            $this->$name = $this->name;
            return $this->$name;
        } elseif ($name == "attributesByName") {
            if ($this->isEditable) {
                throw new InternalInconsistencyException(sprintf("%s property \"%s\" cannot be accessed before initialization", $this->debugDescription(), $name));
            }
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->propertiesByName->filter(fn(PropertyDescription $property): bool => $property instanceof AttributeDescription);
            return $this->$name;
        } elseif ($name == "relationshipsByName") {
            if ($this->isEditable) {
                throw new InternalInconsistencyException(sprintf("%s property \"%s\" cannot be accessed before initialization", $this->debugDescription(), $name));
            }
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->propertiesByName->filter(fn(PropertyDescription $property): bool => $property instanceof RelationshipDescription);
            return $this->$name;
        } else {
            return $this->valueForUndefinedKey($name);
        }
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == "versionHash" || $name == "renamingIdentifier" || $name == "attributesByName" || $name == "relationshipsByName") {
            $this->$name = $value;
        } elseif ($name == "subentities") {
            $this->throwIfNotEditable();
            $this->subentitiesByName->removeAll();
            /** @var EntityDescription $subentity */
            foreach ($value as $subentity) {
                /** @noinspection PhpSecondWriteToReadonlyPropertyInspection */
                $this->subentitiesByName[$subentity->name] = $subentity;
            }
        } elseif ($name == "properties") {
            $this->throwIfNotEditable();
            $this->propertiesByName->removeAll();
            /** @var PropertyDescription $property */
            foreach ($value as $property) {
                if ($property->entity !== $this) {
                    $property = clone $property;
                }
                $property->entity = $this;
                /** @noinspection PhpSecondWriteToReadonlyPropertyInspection */
                $this->propertiesByName[$property->name] = $property;
            }
        } elseif ($name == "indexes") {
            $this->throwIfNotEditable();
            $this->indexes->removeAll();
            /** @var FetchIndexDescription $index */
            foreach ($value as $index) {
                if ($index->entity !== $this) {
                    $index = clone $index;
                }
                $index->entity = $this;
                $this->indexes->append($index);
            }
            $this->indexes->appendContentsOf($this->uniquenessConstraintsAsFetchIndexes());
        } else {
            $this->setValueForUndefinedKey($value, $name);
        }
    }

    private function throwIfNotEditable(): void
    {
        if (!$this->isEditable) {
            throw new InternalInconsistencyException();
        }
    }

    /** @internal */
    public function flattenProperties(): void
    {
        if (!$this->isFlattened) {
            /** @var Set<PropertyDescription> $properties */
            $properties = new Set();
            $superentity = $this->superentity;
            $rootEntity = $superentity;
            while ($superentity) {
                $properties->appendContentsOf($superentity->properties);
                $superentity = $superentity->superentity;
                if ($superentity) {
                    $rootEntity = $superentity;
                }
            }
            $properties->appendContentsOf($this->properties);
            $this->rootEntity = $rootEntity;
            $this->isRootEntity = $rootEntity === null;
            $properties->sort(fn(PropertyDescription $e0, PropertyDescription $e1): int => $e0->propertyType->value <=> $e1->propertyType->value);
            $this->properties = new ArrayClass($properties);
            $this->isFlattened = true;
            $this->isEditable = false;
        }
    }

    /**
     * Returns a Boolean value that indicates whether the receiver is a sub-entity of another given entity.
     * @param EntityDescription $entity An entity.
     * @return bool true if the receiver is a sub-entity of entity, otherwise false.
     */
    public function isKindOf(EntityDescription $entity): bool
    {
        if ($this->isEqual($entity)) {
            return true;
        }
        $superentity = $this->superentity;
        while ($superentity) {
            if ($superentity->isEqual($entity)) {
                return true;
            }
            $superentity = $superentity->superentity;
        }
        return false;
    }

    /**
     * Returns an array containing the relationships of the receiver where the entity description of the relationship is a given entity.
     * @param EntityDescription $destinationEntity An entity description.
     * @return ArrayClass<RelationshipDescription> An array containing the relationships of the receiver where the entity description of the relationship is entity.
     * Elements in the array are instances of {@see RelationshipDescription}.
     */
    public function relationships(EntityDescription $destinationEntity): ArrayClass
    {
        return $this->relationshipsByName->filter(fn(RelationshipDescription $relationship) => $relationship->destinationEntity === $destinationEntity)->values;
    }

    /**
     * Returns the entity with the specified name from the managed object model associated with the specified managed object context's persistent store coordinator.
     * @param string $entityName The name of an entity.
     * @param ManagedObjectContext $context The managed object context to use.
     * @return EntityDescription The entity with the specified name from the managed object model associated with context's persistent store coordinator.
     * @throws InternalInconsistencyException Raises {@see InternalInconsistencyException} if entity with entityName cannot be found.
     */
    public static function entity(string $entityName, ManagedObjectContext $context): EntityDescription
    {
        return $context->persistentStoreCoordinator?->managedObjectModel?->entitiesByName[$entityName] ?? throw new InternalInconsistencyException("Entity \"$entityName\" does not exist");
    }

    /**
     * Creates, configures, and returns an instance of the class for the entity with a given name.
     *
     * This method makes it easy for you to create instances of a given entity without worrying about the details of managed object creation. The method is conceptually similar to the following code example.
     * <code>
     * $managedObjectModel = $context->persistentStoreCoordinator->managedObjectModel;
     * $entity = $managedObjectModel->entitiesByName[$entityName];
     * $newObject = new ManagedObject($entity, $context);
     * return $newObject;
     * </code>
     * @param string $entityName The name of an entity.
     * @param ManagedObjectContext $context The managed object context to use.
     * @return ManagedObject A new, fully configured instance of the class for the entity named entityName. The instance has its entity description set and is inserted it into context.
     * @throws InternalInconsistencyException Raises {@see InternalInconsistencyException} if entity with entityName cannot be found.
     */
    public static function insertNewObject(string $entityName, ManagedObjectContext $context): ManagedObject
    {
        $entity = static::entity($entityName, $context);
        $managedObjectClass = $entity->managedObjectClassName ?? ManagedObject::class;
        /** @psalm-suppress UnsafeInstantiation */
        return new $managedObjectClass($context, $entity);
    }

    /** @internal */
    public function versionHashInStyle(VersionHashStyle $style): string
    {
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = new Dictionary();
        $dictionary["name"] = $this->name;
        if ($this->isAbstract) {
            $dictionary["isAbstract"] = $this->isAbstract;
        }
        $dictionary["versionHashModifier"] = $this->versionHashModifier;
        if ($superentity = $this->superentity) {
            $dictionary["superentity"] = $superentity->versionHashInStyle($style);
        }
        $dictionary["properties"] = $this->properties->compactMap(function (PropertyDescription $property) use ($style): ?Dictionary {
            if ($property instanceof AttributeDescription || $property instanceof RelationshipDescription) {
                $property->versionHashInStyle($data, $style);
                return KeyedUnarchiver::unarchiveTopLevelObjectWithData((string)$data);
            }
            return null;
        });
        /** @noinspection PhpUnhandledExceptionInspection */
        return KeyedArchiver::archivedData($dictionary);
    }

    /**
     * @internal
     */
    public function hasUniquedPropertyNamed(string $name): bool
    {
        return $this->indexes->contains(fn(FetchIndexDescription $index): bool => $index->isUnique() && $index->name === $name);
    }

    /**
     * @param ArrayClass<AttributeDescription|string> $constraint
     * @return FetchIndexDescription|null
     */
    private function constraintAsIndex(ArrayClass $constraint): ?FetchIndexDescription
    {
        /** @var ArrayClass<FetchIndexElementDescription> $elements */
        $elements = $constraint->compactMap(function (AttributeDescription|string $e): ?FetchIndexElementDescription {
            if ($e instanceof AttributeDescription) {
                return new FetchIndexElementDescription($e);
            } elseif ($property = $this->propertiesByName[$e]) {
                return new FetchIndexElementDescription($property);
            }
            return null;
        });
        if ($elements->isEmpty()) {
            return null;
        }
        $name = $elements->map(fn(FetchIndexElementDescription $element): string => $element->property->name)->join("_");
        $index = new FetchIndexDescription($name, $elements);
        $index->setUnique(true);
        return $index;
    }

    /**
     * @return ArrayClass<FetchIndexDescription>
     */
    private function uniquenessConstraintsAsFetchIndexes(): ArrayClass
    {
        /** @var ArrayClass<FetchIndexDescription> */
        return $this->uniquenessConstraints->compactMap(fn(ArrayClass $constraint): ?FetchIndexDescription => $this->constraintAsIndex($constraint));
    }

    public function count(): int
    {
        return $this->propertiesByName->count();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->properties->toArray());
    }

    public function isEqual(mixed $other): bool
    {
        if ($other instanceof EntityDescription) {
            return ($this::class === $other::class) && ($this->managedObjectClassName === $other->managedObjectClassName) && ($this->renamingIdentifier === $other->renamingIdentifier);
        }
        return false;
    }

    public function description(): string
    {
        return sprintf("<%s: %s> isAbstract %s", $this->name, $this->hash(), (int)$this->isAbstract);
    }

    public function jsonSerialize(): Dictionary
    {
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = new Dictionary();
        $dictionary["name"] = $this->name;
        $dictionary["managedObjectClassName"] = $this->managedObjectClassName;
        if ($this->isAbstract) {
            $dictionary["isAbstract"] = $this->isAbstract;
        }
        $dictionary["versionHashModifier"] = $this->versionHashModifier;
        $attributes = $this->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$this->superentity?->attributesByName?->contains(fn(AttributeDescription $e): bool => $e->name === $attribute->name))->map(fn(AttributeDescription $attribute): Dictionary => $attribute->jsonSerialize());
        if (!$attributes->isEmpty()) {
            $dictionary["attributes"] = $attributes;
        }
        $relationships = $this->relationshipsByName->filter(fn(RelationshipDescription $relationship): bool => !$this->superentity?->relationshipsByName?->contains(fn(RelationshipDescription $e): bool => $e->name === $relationship->name))->map(fn(RelationshipDescription $relationship): Dictionary => $relationship->jsonSerialize());
        if (!$relationships->isEmpty()) {
            $dictionary["relationships"] = $relationships;
        }
        $fetchedProperties = $this->propertiesByName->filter(fn(PropertyDescription $property): bool => $property instanceof FetchedPropertyDescription && !$this->superentity?->propertiesByName?->contains(fn(PropertyDescription $e): bool => $e->name === $property->name))->map(fn(PropertyDescription $property): Dictionary => $property->jsonSerialize());
        if (!$fetchedProperties->isEmpty()) {
            $dictionary["fetchedProperties"] = $fetchedProperties;
        }
        $uniquenessConstraints = $this->uniquenessConstraints;
        if (!$uniquenessConstraints->isEmpty()) {
            $dictionary["uniquenessConstraints"] = $uniquenessConstraints;
        }
        $indexes = $this->indexes->filter(fn(FetchIndexDescription $index): bool => !$index->isUnique() && !$this->superentity?->indexes?->contains(fn(FetchIndexDescription $e): bool => $e->name === $index->name))->map(fn(FetchIndexDescription $index): Dictionary => $index->jsonSerialize());
        if (!$indexes->isEmpty()) {
            $dictionary["indexes"] = $indexes;
        }
        $subentities = $this->subentitiesByName->map(fn(EntityDescription $subentity): Dictionary => $subentity->jsonSerialize());
        if (!$subentities->isEmpty()) {
            $dictionary["subentities"] = $subentities;
        }
        return $dictionary;
    }
}
