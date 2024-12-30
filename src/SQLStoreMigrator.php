<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonResult;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;

/** @internal */
readonly class SQLStoreMigrator
{
    private SQLAdapter $adapter;
    private SQLConnection $connection;
    private SQLModel $sourceModel;
    /** @var ArrayClass<SQLEntity> */
    private ArrayClass $removedEntities;
    /** @var ArrayClass<SQLManyToMany> */
    private ArrayClass $removedManyToMany;
    /** @var ArrayClass<SQLColumn> */
    private ArrayClass $removedColumns;

    /**
     * @throws Exception
     */
    public function __construct(public SQLCore $store, public SQLModel $destinationModel, public MappingModel $mappingModel)
    {
        $this->connection = $this->store->schemaValidationConnection;
        $this->adapter = $this->connection->adapter ?? fatal_error();
        $this->sourceModel = new SQLModel($this->connection->cachedModel ?? fatal_error(), $this->store->configurationName);
        $this->removedEntities = new ArrayClass();
        $this->removedManyToMany = new ArrayClass();
        $this->removedColumns = new ArrayClass();
    }

    /**
     * @param EntityMapping $mapping
     * @return SQLEntity[]|null
     */
    private function entities(EntityMapping $mapping): ?array
    {
        if (!($sourceEntityName = $mapping->sourceEntityName)) {
            return null;
        }
        if (!($destinationEntityName = $mapping->destinationEntityName)) {
            return null;
        }
        /** @var SQLEntity|null $sourceEntity */
        $sourceEntity = $this->sourceModel->entitiesByName[$sourceEntityName];
        if (!$sourceEntity) {
            return null;
        }
        /** @var SQLEntity|null $destinationEntity */
        $destinationEntity = $this->destinationModel->entitiesByName[$destinationEntityName];
        if (!$destinationEntity) {
            return null;
        }
        return [$sourceEntity, $destinationEntity];
    }

    /**
     * @param SQLEntity $entity
     * @param ArrayClass<SQLToMany> $toManyRelationships
     * @throws Exception
     */
    private function recreateForeignKeys(SQLEntity $entity, ArrayClass $toManyRelationships): void
    {
        $adapter = $this->adapter;
        $connection = $this->connection;
        foreach ($toManyRelationships as $toManyRelationship) {
            if (!($toMany = $entity->toManyRelationships->first(fn(SQLToMany $toMany): bool => $toMany->name === $toManyRelationship->name))) {
                continue;
            }
            $statement = $adapter->newDropIndexStatementForForeignKey($toManyRelationship->inverseToOne->foreignKey);
            $connection->execute($statement);
            if (!($statement = $adapter->newRenameColumnStatement($toManyRelationship->inverseToOne->foreignKey, $toMany->inverseToOne->foreignKey))) {
                continue;
            }
            $connection->execute($statement);
            $statement = $adapter->newCreateIndexStatementForForeignKey($toMany->inverseToOne->foreignKey);
            $connection->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    public function perform(): void
    {
        $adapter = $this->adapter;
        $connection = $this->connection;
        $sourceModel = $this->sourceModel;
        $destinationModel = $this->destinationModel;
        $mappingModel = $this->mappingModel;
        /** @var Set<EntityMapping> $addedEntityMappings */
        $addedEntityMappings = new Set();
        /** @var Set<EntityMapping> $removedEntityMappings */
        $removedEntityMappings = new Set();
        /** @var Set<EntityMapping> $copiedEntityMappings */
        $copiedEntityMappings = new Set();
        /** @var Set<EntityMapping> $transformedEntityMappings */
        $transformedEntityMappings = new Set();
        foreach ($mappingModel->entityMappingsByName as $mapping) {
            if ($mapping->mappingType === EntityMappingType::addEntityMappingType) {
                $addedEntityMappings->append($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::removeEntityMappingType) {
                $removedEntityMappings->append($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::copyEntityMappingType) {
                $copiedEntityMappings->append($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::transformEntityMappingType) {
                $transformedEntityMappings->append($mapping);
            }
        }
        /** @var ArrayClass<SQLStatement> $createIndexStatements */
        $createIndexStatements = new ArrayClass();
        foreach ($addedEntityMappings as $mapping) {
            if (!($destinationEntityName = $mapping->destinationEntityName)) {
                continue;
            }
            /** @var SQLEntity|null $destinationEntity */
            $destinationEntity = $destinationModel->entitiesByName[$destinationEntityName];
            if (!$destinationEntity) {
                continue;
            }
            $statement = $adapter->newCreateTableStatement($destinationEntity);
            $connection->execute($statement);
            if ($statement = $adapter->newCreateIndexesStatement($destinationEntity)) {
                $createIndexStatements->append($statement);
            }
            foreach ($destinationEntity->manyToManyRelationships as $manyToManyRelationship) {
                $statement = $adapter->newCreateTableStatementForManyToMany($manyToManyRelationship);
                $connection->execute($statement);
                $createIndexStatements->append($adapter->newCreateIndexesStatementForManyToMany($manyToManyRelationship));
            }
        }
        foreach ($removedEntityMappings as $mapping) {
            if (!($sourceEntityName = $mapping->sourceEntityName)) {
                continue;
            }
            /** @var SQLEntity|null $sourceEntity */
            $sourceEntity = $this->sourceModel->entitiesByName[$sourceEntityName];
            if (!$sourceEntity) {
                continue;
            }
            $this->removedEntities->append($sourceEntity);
        }
        foreach ($copiedEntityMappings as $mapping) {
            if (!($entities = $this->entities($mapping))) {
                continue;
            }
            [$sourceEntity, $destinationEntity] = $entities;
            foreach ($sourceEntity->indexes as $index) {
                if (!$destinationEntity->indexes->containsElement($index)) {
                    $connection->execute(SQLStatement::merging($index->dropTableStatements));
                }
            }
            foreach ($destinationEntity->indexes as $index) {
                if (!$sourceEntity->indexes->containsElement($index)) {
                    $createIndexStatements->appendContentsOf($index->createTableStatements);
                }
            }
        }
        foreach ($transformedEntityMappings as $mapping) {
            if (!($entities = $this->entities($mapping))) {
                continue;
            }
            [$sourceEntity, $destinationEntity] = $entities;
            if (!($subentity = $destinationEntity->subentities->first(fn(SQLEntity $subentity): bool => $subentity->entityDescription->renamingIdentifier !== $subentity->entityDescription->name))) {
                continue;
            }
            /** @var ArrayClass<SQLToMany> $toManyRelationships */
            $toManyRelationships = $subentity->entitySpecificRelationships->filter(fn(SQLRelationship $relationship): bool => $relationship instanceof SQLToMany);
            $this->recreateForeignKeys($sourceEntity, $toManyRelationships);
        }
        foreach ($transformedEntityMappings as $mapping) {
            if (!($entities = $this->entities($mapping))) {
                continue;
            }
            [$sourceEntity, $destinationEntity] = $entities;
            foreach ($sourceEntity->indexes as $index) {
                $connection->execute(SQLStatement::merging($index->dropTableStatements));
            }
            if ($sourceEntity->tableName !== $destinationEntity->tableName && !$sourceModel->entitiesByName->offsetExists($destinationEntity->tableName)) {
                $statement = $adapter->newRenameTableStatement($sourceEntity, $destinationEntity);
                $connection->execute($statement);
                if ($statement = $adapter->newModifyColumnStatement($destinationEntity->entityKey, $destinationEntity->primaryKey)) {
                    $connection->execute($statement);
                }
                $this->recreateForeignKeys($destinationEntity, $sourceEntity->toManyRelationships);
            }
            $properties = new Set($sourceEntity->properties);
            $properties->appendContentsOf($destinationEntity->properties);
            foreach ($properties as $property) {
                if ($property instanceof SQLAttribute && $property->attributeDescription instanceof DerivedAttributeDescription && !$property->attributeDescription->derivationExpression?->usesKVC) {
                    $statement = $adapter->newDropColumnStatement($property);
                    $connection->execute($statement);
                }
            }
            foreach ($sourceEntity->properties as $source) {
                if ($destination = $destinationEntity->properties->first(function (SQLProperty $destination) use ($source): bool {
                    if ($source instanceof SQLForeignKey && $destination instanceof SQLForeignKey) {
                        return $source->columnName === $destination->columnName;
                    }
                    if ($source instanceof SQLToMany && $destination instanceof SQLToMany) {
                        return $source->inverseToOne->foreignKey->columnName === $destination->inverseToOne->foreignKey->columnName;
                    }
                    return $source->propertyDescription->renamingIdentifier === $destination->propertyDescription->renamingIdentifier;
                })) {
                    if ($source instanceof SQLAttribute && $destination instanceof SQLAttribute) {
                        if ($source->name !== $destination->name && ($statement = $adapter->newRenameColumnStatement($source, $destination))) {
                            $connection->execute($statement);
                        }
                        if ($source->isCompositeAttribute !== $destination->isCompositeAttribute) {
                            if ($attributes = $sourceEntity->byMappingByCompositeNameAssociationTable->valueForKey($source->name)?->values) {
                                $this->removedColumns->appendContentsOf($attributes);
                            } else {
                                $this->removedColumns->append($source);
                            }
                        } elseif (($source->sqlType !== $destination->sqlType || $source->isOptional !== $destination->isOptional || $source->isUnique !== $destination->isUnique || $source->minValue !== $destination->minValue || $source->maxValue !== $destination->maxValue || $source->defaultValue !== $destination->defaultValue || ($source->isDerivedAttribute !== $destination->isDerivedAttribute) || ($source->isDerivedAttribute && $destination->isDerivedAttribute && (string)$source->derivationExpression !== (string)$destination->derivationExpression)) && ($statement = $adapter->newRenameColumnStatement($source, $destination))) {
                            $connection->execute($statement);
                        }
                        if (!$source->isTransient && $destination->isTransient) {
                            $this->removedColumns->append($source);
                        }
                    } elseif ($source instanceof SQLForeignKey && $destination instanceof SQLForeignKey) {
                        if ($source->toOneRelationship->relationshipDescription->deleteRule !== $destination->toOneRelationship->relationshipDescription->deleteRule) {
                            if ($source->columnName === $destination->columnName) {
                                $statement = $adapter->newDropIndexStatementForForeignKey($source);
                                $connection->execute($statement);
                            }
                            $statement = $adapter->newCreateIndexStatementForForeignKey($destination);
                            $connection->execute($statement);
                        }
                    } elseif ($source instanceof SQLRelationship && $destination instanceof SQLRelationship) {
                        if ($source instanceof $destination) {
                            if ($source instanceof SQLToMany && $destination instanceof SQLToMany && $source->relationshipDescription->deleteRule !== $destination->relationshipDescription->deleteRule) {
                                $statement = $adapter->newDropIndexStatementForForeignKey($source->inverseToOne->foreignKey);
                                $connection->execute($statement);
                                $statement = $adapter->newCreateIndexStatementForForeignKey($destination->inverseToOne->foreignKey);
                                $connection->execute($statement);
                            }
                        } else {
                            if ($source instanceof SQLManyToMany) {
                                $this->removedManyToMany->append($source);
                            }
                            if ($destination instanceof SQLManyToMany) {
                                $statement = $adapter->newCreateTableStatementForManyToMany($destination);
                                $connection->execute($statement);
                                $createIndexStatements->append($adapter->newCreateIndexesStatementForManyToMany($destination));
                            } elseif ($destination instanceof SQLToMany) {
                                if ($statement = $adapter->newCreateColumnStatement($destination->inverseToOne->foreignKey)) {
                                    $connection->execute($statement);
                                }
                            }
                        }
                    }
                } elseif ($source instanceof SQLAttribute || $source instanceof SQLForeignKey) {
                    if ($sourceEntity->isEqual($destinationEntity) && !$destinationEntity->properties->containsElement($source)) {
                        if ($attributes = $sourceEntity->byMappingByCompositeNameAssociationTable->valueForKey($source->name)?->values) {
                            $this->removedColumns->appendContentsOf($attributes);
                        } else {
                            $this->removedColumns->append($source);
                        }
                    }
                } elseif ($source instanceof SQLManyToMany) {
                    $this->removedManyToMany->append($source);
                }
            }
            $properties = $destinationEntity->properties->filter(fn(SQLProperty $property): bool => !$property->isTransient);
            foreach ($properties as $property) {
                if ($property instanceof SQLAttribute || $property instanceof SQLForeignKey) {
                    if ($attributes = $destinationEntity->byMappingByCompositeNameAssociationTable->valueForKey($property->name)?->values) {
                        foreach ($attributes as $attribute) {
                            if ($statement = $adapter->newCreateColumnStatement($attribute)) {
                                $connection->execute($statement);
                            }
                        }
                    } elseif ($statement = $adapter->newCreateColumnStatement($property)) {
                        $connection->execute($statement);
                    }
                } elseif ($property instanceof SQLManyToMany) {
                    $statement = $adapter->newCreateTableStatementForManyToMany($property);
                    $connection->execute($statement);
                    $createIndexStatements->append($adapter->newCreateIndexesStatementForManyToMany($property));
                }
            }
            foreach ($destinationEntity->indexes as $index) {
                $createIndexStatements->appendContentsOf($index->createTableStatements);
            }
            foreach ($properties as $index => $property) {
                if ($property instanceof SQLAttribute && !$property->isCompositeAttribute && ($statement = $adapter->newModifyColumnStatement($property, $destinationEntity->columnAfter($properties->indexBefore($index))))) {
                    $connection->execute($statement);
                }
            }
        }
        foreach ($createIndexStatements as $statement) {
            $connection->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    public function disconnect(): void
    {
        $adapter = $this->adapter;
        $connection = $this->connection;
        foreach ($this->removedManyToMany as $manyToMany) {
            $statement = $adapter->newDropTableStatementForManyToMany($manyToMany);
            $connection->execute($statement);
        }
        $removedColumns = $this->removedColumns->sort(fn(SQLColumn $c1, SQLColumn $c2): int => $c1 instanceof SQLAttribute && $c1->isDerivedAttribute ? ComparisonResult::orderedAscending->value : ComparisonResult::orderedDescending->value);
        foreach ($removedColumns as $removedColumn) {
            $statement = $adapter->newDropColumnStatement($removedColumn);
            $connection->execute($statement);
        }
        foreach ($this->removedEntities as $entity) {
            $foreignKeys = $entity->toManyRelationships->flatMap(fn(SQLToMany $many): ArrayClass => $many->destinationEntity->foreignKeyColumns->filter(fn(SQLForeignKey $foreignKey): bool => $foreignKey->toOneRelationship->isEqual($many->inverseToOne)));
            foreach ($foreignKeys as $foreignKey) {
                $statement = $adapter->newDropIndexStatementForForeignKey($foreignKey);
                $connection->execute($statement);
            }
            foreach ($entity->manyToManyRelationships as $manyToManyRelationship) {
                $statement = $adapter->newDropIndexesStatementForManyToMany($manyToManyRelationship);
                $connection->execute($statement);
                $statement = $adapter->newDropTableStatementForManyToMany($manyToManyRelationship);
                $connection->execute($statement);
            }
            if ($statement = $adapter->newDropIndexesStatement($entity)) {
                $connection->execute($statement);
            }
        }
        foreach ($this->removedEntities as $entity) {
            $statement = $adapter->newDropTableStatement($entity);
            $connection->execute($statement);
        }
        $connection->saveCachedModel($this->destinationModel);
        $connection->disconnect();
    }
}
