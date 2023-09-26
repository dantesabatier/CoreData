<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;

/** @internal */
readonly class SQLStoreMigrator
{
    public SQLAdapter $adapter;
    public SQLConnection $connection;
    public SQLModel $sourceModel;
    /** @var ArrayClass<SQLEntity> */
    private ArrayClass $removedEntities;
    /** @var ArrayClass<SQLManyToMany> */
    private ArrayClass $removedManyToMany;

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
        foreach ($mappingModel->entityMappings as $mapping) {
            if ($mapping->mappingType === EntityMappingType::addEntityMappingType) {
                $addedEntityMappings->append($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::removeEntityMappingType) {
                $removedEntityMappings->append($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::copyEntityMappingType) {
                $copiedEntityMappings->append($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::transformEntityMappingType) {
                $transformedEntityMappings->append($mapping);
                if (($sourceEntityName = $mapping->sourceEntityName) && ($destinationEntityName = $mapping->destinationEntityName) && ($sourceEntity = $sourceModel->entitiesByName[$sourceEntityName]) && ($destinationEntity = $destinationModel->entitiesByName[$destinationEntityName])) {
                    if ($sourceEntity->isRootEntity) {
                        if (!$destinationEntity->isRootEntity) {
                            $removedEntityMappings->append($mapping);
                        }
                    } elseif ($destinationEntity->isRootEntity && !$sourceModel->entitiesByName[$destinationEntityName]) {
                        $addedEntityMappings->append($mapping);
                    }
                }
            }
        }
        /** @var ArrayClass<SQLStatement> $createIndexStatements */
        $createIndexStatements = new ArrayClass();
        foreach ($addedEntityMappings as $mapping) {
            if ($destinationEntityName = $mapping->destinationEntityName) {
                /** @var SQLEntity $destinationEntity */
                $destinationEntity = $destinationModel->entitiesByName[$destinationEntityName];
                /** @var SQLEntity $destinationRootEntity */
                $destinationRootEntity = $destinationEntity->isRootEntity ? $destinationEntity : $destinationEntity->rootEntity;
                $statement = $adapter->newCreateTableStatement($destinationRootEntity);
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
        }
        foreach ($removedEntityMappings as $mapping) {
            if (($sourceEntityName = $mapping->sourceEntityName) && ($sourceEntity = $sourceModel->entity($sourceEntityName))) {
                $this->removedEntities->append($sourceEntity);
            }
        }
        foreach ($copiedEntityMappings as $mapping) {
            /** @var string $sourceEntityName */
            $sourceEntityName = $mapping->sourceEntityName;
            /** @var string $destinationEntityName */
            $destinationEntityName = $mapping->destinationEntityName;
            /** @var SQLEntity $sourceEntity */
            $sourceEntity = $sourceModel->entitiesByName[$sourceEntityName];
            /** @var SQLEntity $destinationEntity */
            $destinationEntity = $destinationModel->entitiesByName[$destinationEntityName];
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
            /** @var string $sourceEntityName */
            $sourceEntityName = $mapping->sourceEntityName;
            /** @var string $destinationEntityName */
            $destinationEntityName = $mapping->destinationEntityName;
            /** @var SQLEntity $sourceEntity */
            $sourceEntity = $sourceModel->entitiesByName[$sourceEntityName];
            /** @var SQLEntity $destinationEntity */
            $destinationEntity = $destinationModel->entitiesByName[$destinationEntityName];
            if ($sourceEntity->isRootEntity && $destinationEntity->isRootEntity && $sourceEntityName !== $destinationEntityName && !$sourceModel->entitiesByName[$destinationEntityName]) {
                $statement = $adapter->newRenameTableStatement($sourceEntity, $destinationEntity);
                $connection->execute($statement);
            }
            foreach ($sourceEntity->properties as $source) {
                if ($destination = $destinationEntity->properties->first(fn(SQLProperty $destination): bool => $destination->propertyDescription->renamingIdentifier === $source->propertyDescription->renamingIdentifier)) {
                    if ($source instanceof SQLAttribute && $destination instanceof SQLAttribute) {
                        if ($destination->name !== $source->name && ($statement = $adapter->newRenameColumnStatement($destination, $source))) {
                            $connection->execute($statement);
                        }
                        if (($destination->sqlType !== $source->sqlType || $destination->isOptional !== $source->isOptional || $destination->propertyDescription->maxValue !== $source->propertyDescription->maxValue || $destination->attributeDescription->defaultValue !== $source->attributeDescription->defaultValue || ($destination->attributeDescription instanceof DerivedAttributeDescription && $source->attributeDescription instanceof DerivedAttributeDescription && (string)$destination->attributeDescription->derivationExpression !== (string)$source->attributeDescription->derivationExpression)) && ($statement = $adapter->newRenameColumnStatement($destination))) {
                            $connection->execute($statement);
                        }
                    } elseif ($source instanceof SQLForeignKey && $destination instanceof SQLForeignKey) {
                        if ($source->toOneRelationship->relationshipDescription->deleteRule !== $destination->toOneRelationship->relationshipDescription->deleteRule) {
                            $statement = $adapter->newDropIndexStatementForForeignKey($source);
                            $connection->execute($statement);
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
                                $statement = $adapter->newDropIndexesStatementForManyToMany($source);
                                $connection->execute($statement);
                                $this->removedManyToMany->append($source);
                            }
                            if ($destination instanceof SQLManyToMany) {
                                $statement = $adapter->newCreateTableStatementForManyToMany($destination);
                                $connection->execute($statement);
                                $createIndexStatements->append($adapter->newCreateIndexesStatementForManyToMany($destination));
                            }
                        }
                    }
                } elseif ($source instanceof SQLAttribute || $source instanceof SQLForeignKey) {
                    if (!$sourceEntity->isRootEntity && !$destinationEntity->isRootEntity && $sourceEntity->rootEntity->isEqual($destinationEntity->rootEntity) && !$destinationEntity->properties->containsElement($source)) {
                        if ($statement = $adapter->newDropIndexStatement($source)) {
                            $connection->execute($statement);
                        }
                        $statement = $adapter->newDropColumnStatement($source);
                        $connection->execute($statement);
                    }
                } elseif ($source instanceof SQLManyToMany) {
                    $statement = $adapter->newDropIndexesStatementForManyToMany($source);
                    $connection->execute($statement);
                    $this->removedManyToMany->append($source);
                }
            }
            /** @var SQLEntity $sourceRootEntity */
            $sourceRootEntity = $sourceEntity->isRootEntity ? $sourceEntity : $sourceEntity->rootEntity;
            /** @var SQLEntity $destinationRootEntity */
            $destinationRootEntity = $destinationEntity->isRootEntity ? $destinationEntity : $destinationEntity->rootEntity;
            foreach (clone $destinationRootEntity->properties as $index => $property) {
                if (($property instanceof SQLAttribute || $property instanceof SQLForeignKey) && $destinationEntity->properties->contains(fn(SQLProperty $e): bool => $e->propertyDescription->renamingIdentifier === $property->propertyDescription->renamingIdentifier) && !$sourceEntity->properties->contains(fn(SQLProperty $e): bool => $e->propertyDescription->renamingIdentifier === $property->propertyDescription->renamingIdentifier) && ($statement = $adapter->newCreateColumnStatement($property, $destinationRootEntity->columnAfter($property instanceof SQLForeignKey && !$sourceRootEntity->properties->contains(fn(SQLProperty $e): bool => $e->propertyDescription->renamingIdentifier === $property->propertyDescription->renamingIdentifier) ? $destinationRootEntity->attributes->indexAfter($destinationRootEntity->attributes->endIndex()) : $destinationRootEntity->properties->indexBefore($index))))) {
                    $connection->execute($statement);
                    if ($statement = $adapter->newCreateIndexStatement($property)) {
                        $connection->execute($statement);
                    }
                } elseif ($property instanceof SQLManyToMany) {
                    $statement = $adapter->newCreateTableStatementForManyToMany($property);
                    $connection->execute($statement);
                    $createIndexStatements->append($adapter->newCreateIndexesStatementForManyToMany($property));
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
        foreach ($this->removedEntities as $entity) {
            foreach ($entity->toManyRelationships->flatMap(fn(SQLToMany $many): ArrayClass => $many->destinationEntity->foreignKeyColumns->filter(fn(SQLForeignKey $foreignKey): bool => $foreignKey->toOneRelationship->isEqual($many->inverseToOne))) as $foreignKey) {
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
