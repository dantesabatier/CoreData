<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Set;
use Throwable;

/** @internal */
class SQLStoreMigrator
{
    public readonly SQLAdapter $adapter;
    public readonly SQLConnection $connection;
    public readonly SQLModel $sourceModel;
    /** @var ArrayClass<SQLEntity> */
    private readonly ArrayClass $removedEntities;

    /**
     * @throws Exception
     */
    public function __construct(public readonly SQLCore $store, public readonly SQLModel $destinationModel, public readonly MappingModel $mappingModel)
    {
        $this->connection = $this->store->schemaValidationConnection;
        $this->adapter = $this->connection->adapter ?? throw new InternalInconsistencyException();
        $this->sourceModel = new SQLModel($this->connection->fetchCachedModel() ?? throw new InternalInconsistencyException(), $this->store->configurationName);
        $this->removedEntities = new ArrayClass();
    }

    /**
     * @throws Exception
     */
    public function perform(): void
    {
        try {
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
                if ($mapping->mappingType == EntityMappingType::addEntityMappingType) {
                    $addedEntityMappings->append($mapping);
                } elseif ($mapping->mappingType == EntityMappingType::removeEntityMappingType) {
                    $removedEntityMappings->append($mapping);
                } elseif ($mapping->mappingType == EntityMappingType::copyEntityMappingType) {
                    $copiedEntityMappings->append($mapping);
                } elseif ($mapping->mappingType == EntityMappingType::transformEntityMappingType) {
                    $transformedEntityMappings->append($mapping);
                    if (($sourceEntityName = $mapping->sourceEntityName) && ($destinationEntityName = $mapping->destinationEntityName) && ($sourceEntity = $sourceModel->entitiesByName[$sourceEntityName]) && ($destinationEntity = $destinationModel->entitiesByName[$destinationEntityName])) {
                        if ($sourceEntity->isRootEntity) {
                            if (!$destinationEntity->isRootEntity) {
                                $removedEntityMappings->append($mapping);
                            }
                        } else {
                            if ($destinationEntity->isRootEntity && !$sourceModel->entitiesByName[$destinationEntityName]) {
                                $addedEntityMappings->append($mapping);
                            }
                        }
                    }
                }
            }
            /** @var ArrayClass<SQLStatement> $createIndexStatements */
            $createIndexStatements = new ArrayClass();
            foreach ($addedEntityMappings as $mapping) {
                if (($destinationEntityName = $mapping->destinationEntityName) && !$sourceModel->entitiesByName[$destinationEntityName]) {
                    /** @var SQLEntity $destinationEntity */
                    $destinationEntity = $destinationModel->entitiesByName[$destinationEntityName];
                    /** @var SQLEntity $rootEntity */
                    $rootEntity = $destinationEntity->isRootEntity ? $destinationEntity : $destinationEntity->rootEntity;
                    $statement = $adapter->newCreateTableStatement($rootEntity);
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
            foreach ($createIndexStatements as $statement) {
                $connection->execute($statement);
            }
            $this->removedEntities->removeAll();
            foreach ($removedEntityMappings as $mapping) {
                if (($sourceEntityName = $mapping->sourceEntityName) && ($sourceEntity = $sourceModel->entity($sourceEntityName))) {
                    $this->removedEntities->append($sourceEntity);
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
                    if ($source instanceof SQLAttribute || $source instanceof SQLForeignKey) {
                        /** @psalm-suppress ArgumentTypeCoercion */
                        if ($destination = $destinationEntity->properties->first(fn(SQLProperty $destination): bool => $destination->propertyDescription->renamingIdentifier === $source->propertyDescription->renamingIdentifier)) {
                            if ($destination->name != $source->name) {
                                if ($statement = $adapter->newRenameColumnStatement($destination, $source)) {
                                    $connection->execute($statement);
                                }
                            }
                            if ($destination->sqlType != $source->sqlType || $destination->isOptional != $source->isOptional || $destination->propertyDescription->maxValue != $source->propertyDescription->maxValue) {
                                if ($statement = $adapter->newRenameColumnStatement($destination)) {
                                    $connection->execute($statement);
                                }
                            }
                        } else {
                            if ($statement = $adapter->newDropIndexStatement($source)) {
                                $connection->execute($statement);
                            }
                            $statement = $adapter->newDropColumnStatement($source);
                            $connection->execute($statement);
                        }
                    }
                }
                $properties = $destinationEntity->properties;
                foreach ($properties as $index => $property) {
                    if ($property instanceof SQLAttribute || $property instanceof SQLForeignKey) {
                        if (!$property->propertyDescription instanceof DerivedAttributeDescription && !$sourceEntity->properties->contains(fn(SQLProperty $e): bool => $e->propertyDescription->renamingIdentifier === $property->propertyDescription->renamingIdentifier)) {
                            /** @var SQLColumn $after */
                            $after = $index ? $properties[$properties->indexBefore($index)] : $destinationEntity->entityKey;
                            if ($statement = $adapter->newCreateColumnStatement($property, $after)) {
                                $connection->execute($statement);
                                if ($statement = $adapter->newCreateIndexStatement($property)) {
                                    $connection->execute($statement);
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable $throwable) {
            $throwableClass = $throwable::class;
            throw new $throwableClass($throwable->getMessage(), (int)$throwable->getCode());
        }
    }

    /**
     * @throws Exception
     */
    public function disconnect(): void
    {
        $adapter = $this->adapter;
        $connection = $this->connection;
        foreach ($this->removedEntities as $entity) {
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
