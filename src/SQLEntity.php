<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
class SQLEntity extends StoreMapping
{
    public const string primaryKeyName = "objectID";
    public const string entityKeyName = "entityName";
    public string $tableName {
        get => $this->associatedValues[__PROPERTY__] ??= $this->tableName();
    }
    public SQLPrimaryKey $primaryKey {
        get => $this->associatedValues[__PROPERTY__] ??= $this->primaryKey();
    }
    public SQLEntityKey $entityKey {
        get => $this->associatedValues[__PROPERTY__] ??= $this->entityKey();
    }
    /** @var ArrayClass<SQLEntity> */
    public ArrayClass $subentities {
        get => $this->associatedValues[__PROPERTY__] ??= $this->entityDescription->subentities->map(fn(EntityDescription $subentity): SQLEntity => $this->model->entitiesByName[$subentity->name]);
    }
    public ?SQLEntity $superentity {
        get => $this->associatedValues[__PROPERTY__] ??= $this->model->entitiesByName->first(fn(SQLEntity $entity): bool => $entity->entityDescription->isEqual($this->entityDescription->superentity));
    }
    public ?SQLEntity $rootEntity {
        get => $this->associatedValues[__PROPERTY__] ??= $this->rootEntity();
    }
    public bool $isRootEntity {
        get => $this->superentity === null;
    }
    /** @var Dictionary<SQLProperty> */
    public Dictionary $propertiesByName {
        get => $this->associatedValues[__PROPERTY__] ??= $this->propertiesByName();
    }
    /** @var ArrayClass<SQLProperty> */
    public ArrayClass $properties {
        get => $this->associatedValues[__PROPERTY__] ??= $this->propertiesByName->values;
    }
    /** @var ArrayClass<SQLProperty> */
    public ArrayClass $uniqueProperties {
        get => $this->associatedValues[__PROPERTY__] ??= $this->properties->filter(fn(SQLProperty $property): bool => $property->isUnique);
    }
    /** @var ArrayClass<SQLAttribute> */
    public ArrayClass $attributes {
        get => $this->associatedValues[__PROPERTY__] ??= $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLAttribute);
    }
    /** @var ArrayClass<SQLAttribute> */
    public ArrayClass $derivedAttributes {
        get => $this->associatedValues[__PROPERTY__] ??= $this->attributes->filter(fn(SQLAttribute $attribute): bool => $attribute->attributeDescription instanceof DerivedAttributeDescription);
    }
    /** @var ArrayClass<ArrayClass<SQLAttribute>> */
    public ArrayClass $multiColumnUniquenessConstraints {
        get => $this->associatedValues[__PROPERTY__] ??= $this->entityDescription->uniquenessConstraints->map(fn(ArrayClass $uniquenessConstraints): ArrayClass => $uniquenessConstraints->compactMap(fn(AttributeDescription|string $description): ?SQLAttribute => $this->attributes->first(fn(SQLAttribute $attribute): bool => $attribute->name === ($description instanceof AttributeDescription ? $description->name : $description))));
    }
    /** @var Dictionary<SQLIndex> */
    public Dictionary $indexes {
        get => $this->associatedValues[__PROPERTY__] ??= $this->indexes();
    }
    /** @var Dictionary<SQLRTreeIndex> */
    public Dictionary $rTreeIndexes {
        get => $this->associatedValues[__PROPERTY__] ??= $this->indexes->filter(fn(SQLIndex $index): bool => $index instanceof SQLRTreeIndex);
    }
    // TODO: implement optimistic locking
    public ?SQLOptLockKey $optLockKey {
        get => null;
    }
    /** @var ArrayClass<SQLForeignKey> */
    public ArrayClass $foreignKeyColumns {
        get => $this->associatedValues[__PROPERTY__] ??= $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLRelationship)->compactMap(fn(SQLRelationship $relationship): ?SQLForeignKey => $relationship instanceof SQLToOne ? $relationship->foreignKey : null);
    }
    /** @var ArrayClass<SQLForeignKey> */
    public ArrayClass $virtualForeignKeyColumns {
        get => $this->associatedValues[__PROPERTY__] ??= $this->foreignKeyColumns->filter(fn(SQLForeignKey $foreignKey): bool => $foreignKey->toOneRelationship->isVirtual);
    }
    /** @var ArrayClass<SQLForeignEntityKey> */
    public ArrayClass $foreignEntityKeyColumns {
        get => $this->associatedValues[__PROPERTY__] ??= new ArrayClass();
    }
    /** @var ArrayClass<SQLForeignOrderKey> */
    public ArrayClass $foreignOrderKeyColumns {
        get => $this->associatedValues[__PROPERTY__] ??= new ArrayClass();
    }
    /** @var ArrayClass<SQLAttribute> */
    public ArrayClass $entitySpecificAttributes {
        get => $this->associatedValues[__PROPERTY__] ??= $this->entityDescription->relationshipsByName->map(fn(RelationshipDescription $relationshipDescription): SQLRelationship => $this->propertiesByName[$relationshipDescription->name]);
    }
    /** @var ArrayClass<SQLRelationship> */
    public ArrayClass $entitySpecificRelationships {
        get => $this->associatedValues[__PROPERTY__] ??= $this->entityDescription->relationshipsByName->map(fn(RelationshipDescription $relationshipDescription): SQLRelationship => $this->propertiesByName[$relationshipDescription->name]);
    }
    /** @var ArrayClass<SQLToMany> */
    public ArrayClass $toManyRelationships {
        get => $this->associatedValues[__PROPERTY__] ??= $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLToMany);
    }
    /** @var ArrayClass<SQLManyToMany> */
    public ArrayClass $manyToManyRelationships {
        get => $this->associatedValues[__PROPERTY__] ??= $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLManyToMany);
    }
    /** @var ArrayClass<SQLColumn> */
    public ArrayClass $columnsToFetch {
        get => $this->associatedValues[__PROPERTY__] ??= $this->properties->filter(fn(SQLProperty $property): bool => !$property->isTransient && (($property instanceof SQLAttribute ? ($property->isDerivedAttribute && !$property->derivationExpression?->usesKVC || !$property->isDerivedAttribute) : !$property instanceof SQLRelationship && !$property instanceof SQLForeignKey)));
    }
    /** @var ArrayClass<SQLColumn> */
    public ArrayClass $columnsToCreate {
        get => $this->associatedValues[__PROPERTY__] ??= $this->properties->filter(fn(SQLProperty $property): bool => !$property instanceof SQLRelationship && !($property instanceof SQLAttribute && $property->isDerivedAttribute && $property->derivationExpression?->usesKVC));
    }
    public readonly Dictionary $compositeAttributeNameToSQLProperties;
    //TODO: not implemented
    public int $entityID = 0;
    public int $subentityMaxID {
        get => 0;
    }
    public string $description {
        get => sprintf("<%s %s>", $this->entityDescription->name, $this->hash);
    }

    public function __construct(public readonly SQLModel $model, public readonly EntityDescription $entityDescription)
    {
    }

    private function tableName(): string
    {
        /** @var SQLEntity $entity */
        $entity = $this->isRootEntity ? $this : $this->rootEntity;
        return $entity->entityDescription->name;
    }

    private function rootEntity(): ?SQLEntity
    {
        $superentity = $this->superentity;
        $rootEntity = $superentity;
        while ($superentity) {
            $superentity = $superentity->superentity;
            if ($superentity) {
                $rootEntity = $superentity;
            }
        }
        return $rootEntity;
    }

    private function entityKey(): SQLEntityKey
    {
        $attribute = new AttributeDescription();
        $attribute->entity = $this->entityDescription;
        $attribute->name = self::entityKeyName;
        $attribute->type = AttributeType::string;
        $attribute->isOptional = false;
        return new SQLEntityKey($this, $attribute);
    }

    private function primaryKey(): SQLPrimaryKey
    {
        $attribute = new AttributeDescription();
        $attribute->entity = $this->entityDescription;
        $attribute->name = match ($this->entityDescription->name) {
            "PersistentHistoryTransaction" => "transactionID",
            "PersistentHistoryChange" => "changeID",
            default => self::primaryKeyName,
        };
        $attribute->type = AttributeType::integer32;
        $attribute->isOptional = false;
        return new SQLPrimaryKey($this, $attribute);
    }

    private function propertiesByName(): Dictionary
    {
        $transform = function (PropertyDescription $propertyDescription): ?SQLProperty {
            if ($propertyDescription instanceof AttributeDescription) {
                return new SQLAttribute($this, $propertyDescription);
            }
            if ($propertyDescription instanceof RelationshipDescription) {
                if ($propertyDescription->isToMany) {
                    if ($propertyDescription->inverseRelationship->isToMany) {
                        return new SQLManyToMany($this, $propertyDescription);
                    }
                    return new SQLToMany($this, $propertyDescription);
                }
                return new SQLToOne($this, $propertyDescription);
            }
            return null;
        };
        /** @var Dictionary<SQLProperty> $propertiesByName */
        $propertiesByName = $this->entityDescription->propertiesByName->compactMapValues($transform);
        foreach ($this->entityDescription->subentities as $subentity) {
            /** @psalm-suppress InvalidArgument */
            $propertiesByName->merge($subentity->propertiesByName->compactMapValues($transform));
        }
        return $propertiesByName;
    }

    private function indexes(): Dictionary
    {
        /** @var Dictionary<SQLIndex> $indexes */
        $indexes = new Dictionary();
        if (!$this->entityDescription->isPersistentHistoryEntity) {
            $indexes[self::entityKeyName] = new SQLIndex(new FetchIndexDescription(self::entityKeyName, new ArrayClass([new FetchIndexElementDescription($this->entityKey->propertyDescription)])), $this);
        }
        /** @psalm-suppress PossiblyInvalidArgument */
        $indexes->merge($this->entityDescription->indexes->reduce(new Dictionary(), function (Dictionary $result, FetchIndexDescription $indexDescription): Dictionary {
            if ($indexDescription->isSpatial) {
                /** @psalm-suppress InvalidArgument */
                $result[$indexDescription->name] = new SQLRTreeIndex($indexDescription, $this);
            } elseif ($indexDescription->isBinary) {
                /** @psalm-suppress InvalidArgument */
                $result[$indexDescription->name] = new SQLBinaryIndex($indexDescription, $this);
            } else {
                /** @psalm-suppress InvalidArgument */
                $result[$indexDescription->name] = new SQLIndex($indexDescription, $this);
            }
            return $result;
        }));
        return $indexes;
    }

    /** @noinspection PhpHookedPropertyCantBeAccessedByRefInspection */
    public function generateInverseRelationshipsAndMore(): void
    {
        $propertiesByName = $this->propertiesByName;
        $propertiesByName[$this->primaryKey->columnName] = $this->primaryKey;
        if (!$this->entityDescription->isPersistentHistoryEntity) {
            $propertiesByName[$this->entityKey->columnName] = $this->entityKey;
        }
        foreach ($this->foreignKeyColumns as $foreignKeyColumn) {
            $propertiesByName[$foreignKeyColumn->columnName] = $foreignKeyColumn;
            $this->properties->append($foreignKeyColumn);
        }
    }

    public function doPostModelGenerationCleanup(): void
    {
        $by = fn(SQLProperty $e0, SQLProperty $e1): int => $e0->propertyType->value <=> $e1->propertyType->value;
        $this->propertiesByName->sort($by);
        $this->properties->sort($by);
    }

    public function columnAfter(int $index): SQLColumn
    {
        $properties = $this->properties->filter(fn(SQLProperty $property): bool => !$property->propertyDescription->isTransient);
        $max = $properties->endIndex;
        $i = max(min($index, $properties->indexBefore($max)), $properties->startIndex);
        while ($i < $max) {
            $property = $properties[$i];
            if ($property instanceof SQLAttribute) {
                if ($property->isDerivedAttribute) {
                    if (!$property->derivationExpression?->usesKVC) {
                        return $property;
                    }
                } else {
                    return $property;
                }
            } elseif ($property instanceof SQLEntityKey || $property instanceof SQLForeignKey) {
                return $property;
            }
            $properties->formIndexAfter($i);
        }
        return $this->entityKey;
    }

    public function isKindOfSQLEntity(SQLEntity $entity): bool
    {
        return $this->entityDescription->isKindOf($entity->entityDescription);
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLEntity) {
            return $this->entityDescription->isEqual($other->entityDescription);
        }
        return false;
    }
}
