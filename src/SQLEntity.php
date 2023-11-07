<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
class SQLEntity extends StoreMapping
{
    public const primaryKeyName = "objectID";
    public const entityKeyName = "entityName";
    public readonly string $tableName;
    /** @var ArrayClass<SQLEntity> */
    public readonly ArrayClass $subentities;
    public readonly ?SQLEntity $superentity;
    public readonly ?SQLEntity $rootEntity;
    /** @var Dictionary<SQLProperty> */
    public readonly Dictionary $propertiesByName;
    /** @var ArrayClass<SQLProperty> */
    public readonly ArrayClass $properties;
    /** @var ArrayClass<SQLProperty> */
    public readonly ArrayClass $uniqueProperties;
    /** @var ArrayClass<SQLAttribute> */
    public readonly ArrayClass $attributes;
    /** @var ArrayClass<SQLAttribute> */
    public readonly ArrayClass $derivedAttributes;
    /** @var ArrayClass<ArrayClass<SQLAttribute>> */
    public readonly ArrayClass $multiColumnUniquenessConstraints;
    /** @var Dictionary<SQLIndex> */
    public readonly Dictionary $indexes;
    /** @var Dictionary<SQLRTreeIndex> */
    public readonly Dictionary $rTreeIndexes;
    // TODO: implement optimistic locking
    public readonly ?SQLOptLockKey $optLockKey;
    public readonly SQLPrimaryKey $primaryKey;
    public readonly SQLEntityKey $entityKey;
    /** @var ArrayClass<SQLForeignKey> */
    public readonly ArrayClass $foreignKeyColumns;
    /** @var ArrayClass<SQLForeignKey> */
    public readonly ArrayClass $virtualForeignKeyColumns;
    /** @var ArrayClass<SQLForeignEntityKey> */
    public readonly ArrayClass $foreignEntityKeyColumns;
    /** @var ArrayClass<SQLForeignOrderKey> */
    public readonly ArrayClass $foreignOrderKeyColumns;
    public readonly bool $isRootEntity;
    /** @var ArrayClass<SQLAttribute> */
    public readonly ArrayClass $entitySpecificAttributes;
    /** @var ArrayClass<SQLRelationship> */
    public readonly ArrayClass $entitySpecificRelationships;
    /** @var ArrayClass<SQLToMany> */
    public readonly ArrayClass $toManyRelationships;
    /** @var ArrayClass<SQLManyToMany> */
    public readonly ArrayClass $manyToManyRelationships;
    /** @var ArrayClass<SQLColumn> */
    public readonly ArrayClass $columnsToFetch;
    /** @var ArrayClass<SQLColumn> */
    public readonly ArrayClass $columnsToCreate;
    //TODO: not implemented
    public int $entityID;
    public readonly int $subentityMaxID;

    public function __construct(public readonly SQLModel $model, public readonly EntityDescription $entityDescription)
    {
        unset($this->optLockKey);
        unset($this->primaryKey);
        unset($this->entityKey);
        unset($this->tableName);
        unset($this->isRootEntity);
        unset($this->rootEntity);
        unset($this->superentity);
        unset($this->subentities);
        unset($this->foreignKeyColumns);
        unset($this->virtualForeignKeyColumns);
        unset($this->uniqueProperties);
        unset($this->multiColumnUniquenessConstraints);
        unset($this->derivedAttributes);
        unset($this->entitySpecificAttributes);
        unset($this->entitySpecificRelationships);
        unset($this->toManyRelationships);
        unset($this->manyToManyRelationships);
        unset($this->propertiesByName);
        unset($this->properties);
        unset($this->attributes);
        unset($this->indexes);
        unset($this->rTreeIndexes);
        unset($this->columnsToFetch);
        unset($this->columnsToCreate);
        unset($this->entityID);
        unset($this->subentityMaxID);
    }

    public function __get(string $name)
    {
        if ($name == "tableName") {
            /** @var SQLEntity $entity */
            $entity = $this->isRootEntity ? $this : $this->rootEntity;
            $this->$name = $entity->entityDescription->name;
            return $this->$name;
        } elseif ($name == "superentity") {
            $this->$name = ($superentity = $this->entityDescription->superentity) ? $this->model->entitiesByName[$superentity->name] : null;
            return $this->$name;
        } elseif ($name == "subentities") {
            /** @psalm-suppress all */
            $this->$name = $this->entityDescription->subentities->map(fn(EntityDescription $subentity): SQLEntity => $this->model->entitiesByName[$subentity->name]);
            return $this->$name;
        } elseif ($name == "isRootEntity") {
            $this->$name = $this->superentity === null;
            return $this->$name;
        } elseif ($name == "rootEntity") {
            $superentity = $this->superentity;
            $rootEntity = $superentity;
            while ($superentity) {
                $superentity = $superentity->superentity;
                if ($superentity) {
                    $rootEntity = $superentity;
                }
            }
            $this->$name = $rootEntity;
            return $this->$name;
        } elseif ($name == "entityKey") {
            $attribute = new AttributeDescription();
            $attribute->entity = $this->entityDescription;
            $attribute->name = self::entityKeyName;
            $attribute->type = AttributeType::string;
            $attribute->isOptional = false;
            $this->$name = new SQLEntityKey($this, $attribute);
            return $this->$name;
        } elseif ($name == "primaryKey") {
            $attribute = new AttributeDescription();
            $attribute->entity = $this->entityDescription;
            $attribute->name = match ($this->entityDescription->name) {
                "PersistentHistoryTransaction" => "transactionID",
                "PersistentHistoryChange" => "changeID",
                default => self::primaryKeyName,
            };
            $attribute->type = AttributeType::integer32;
            $attribute->isOptional = false;
            $this->$name = new SQLPrimaryKey($this, $attribute);
            return $this->$name;
        } elseif ($name == "optLockKey") {
            $this->$name = null;
            return $this->$name;
        } elseif ($name == "propertiesByName") {
            $transform = function (PropertyDescription $propertyDescription): ?SQLProperty {
                if ($propertyDescription instanceof AttributeDescription) {
                    return new SQLAttribute($this, $propertyDescription);
                } elseif ($propertyDescription instanceof RelationshipDescription) {
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
            $this->$name = $propertiesByName;
            return $this->$name;
        } elseif ($name == "properties") {
            $this->$name = $this->propertiesByName->values;
            return $this->$name;
        } elseif ($name == "uniqueProperties") {
            $this->$name = $this->properties->filter(fn(SQLProperty $property): bool => $property->isUnique);
            return $this->$name;
        } elseif ($name == "attributes") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLAttribute);
            return $this->$name;
        } elseif ($name == "derivedAttributes") {
            $this->$name = $this->attributes->filter(fn(SQLAttribute $attribute): bool => $attribute->attributeDescription instanceof DerivedAttributeDescription);
            return $this->$name;
        } elseif ($name == "entitySpecificAttributes") {
            /** @psalm-suppress all */
            $this->$name = $this->entityDescription->attributesByName->map(fn(AttributeDescription $attributeDescription): SQLAttribute => $this->propertiesByName[$attributeDescription->name]);
            return $this->$name;
        } elseif ($name == "entitySpecificRelationships") {
            /** @psalm-suppress all */
            $this->$name = $this->entityDescription->relationshipsByName->map(fn(RelationshipDescription $relationshipDescription): SQLRelationship => $this->propertiesByName[$relationshipDescription->name]);
            return $this->$name;
        } elseif ($name == "toManyRelationships") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLToMany);
            return $this->$name;
        } elseif ($name == "manyToManyRelationships") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLManyToMany);
            return $this->$name;
        } elseif ($name == "foreignKeyColumns") {
            /** @psalm-suppress all */
            $this->$name = $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLRelationship)->compactMap(fn(SQLRelationship $relationship): ?SQLForeignKey => $relationship instanceof SQLToOne ? $relationship->foreignKey : null);
            return $this->$name;
        } elseif ($name == "virtualForeignKeyColumns") {
            $this->$name = $this->foreignKeyColumns->filter(fn(SQLForeignKey $foreignKey): bool => $foreignKey->toOneRelationship->isVirtual);
            return $this->$name;
        } elseif ($name == "multiColumnUniquenessConstraints") {
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $this->$name = $this->entityDescription->uniquenessConstraints->map(fn(ArrayClass $uniquenessConstraints): ArrayClass => $uniquenessConstraints->compactMap(fn(AttributeDescription|string $description): ?SQLAttribute => $this->attributes->first(fn(SQLAttribute $attribute): bool => $attribute->name === ($description instanceof AttributeDescription ? $description->name : $description))));
            return $this->$name;
        } elseif ($name == "indexes") {
            $updateAccumulatingResult = function (Dictionary $result, FetchIndexDescription $indexDescription): Dictionary {
                if ($indexDescription->isSpatial()) {
                    $result[$indexDescription->name] = new SQLRTreeIndex($indexDescription, $this);
                } elseif ($indexDescription->isBinary()) {
                    $result[$indexDescription->name] = new SQLBinaryIndex($indexDescription, $this);
                } else {
                    $result[$indexDescription->name] = new SQLIndex($indexDescription, $this);
                }
                return $result;
            };
            /** @var Dictionary<SQLIndex> $indexes */
            $indexes = $this->entityDescription->indexes->reduce(new Dictionary(), $updateAccumulatingResult);
            foreach ($this->entityDescription->subentities as $subentity) {
                /** @psalm-suppress PossiblyInvalidArgument */
                $indexes->merge($subentity->indexes->reduce(new Dictionary(), $updateAccumulatingResult));
            }
            $this->$name = $indexes;
            return $this->$name;
        } elseif ($name == "rTreeIndexes") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->indexes->filter(fn(SQLIndex $index): bool => $index instanceof SQLRTreeIndex);
            return $this->$name;
        } elseif ($name == "columnsToFetch") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLAttribute ? ($property->isDerivedAttribute && !$property->derivationExpression?->usesKVC || !$property->isDerivedAttribute) : !$property instanceof SQLRelationship && !$property instanceof SQLForeignKey);
            return $this->$name;
        } elseif ($name == "columnsToCreate") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->properties->filter(fn(SQLProperty $property): bool => $property instanceof SQLAttribute ? ($property->isDerivedAttribute && !$property->derivationExpression?->usesKVC || !$property->isDerivedAttribute) : !$property instanceof SQLRelationship);
            return $this->$name;
        } elseif ($name == "entityID") {
            $this->$name = 0;
            return $this->$name;
        } elseif ($name == "subentityMaxID") {
            $this->$name = 0;
            return $this->$name;
        } else {
            return $this->valueForUndefinedKey($name);
        }
    }

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
        $start = $index;
        $end = $this->properties->endIndex();
        while ($start < $end) {
            $property = $this->properties[$start];
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
            $this->properties->formIndexAfter($start);
        }
        return $this->entityKey;
    }

    public function isKindOfSQLEntity(SQLEntity $entity): bool
    {
        return $this->entityDescription->isKindOf($entity->entityDescription);
    }

    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLEntity) {
            return $this->entityDescription->isEqual($other->entityDescription);
        }
        return false;
    }

    public function description(): string
    {
        return sprintf("<%s %s>", $this->entityDescription->name, $this->hash());
    }
}
