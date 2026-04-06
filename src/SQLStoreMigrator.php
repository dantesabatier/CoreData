<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonResult;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SQLStoreMigrator
{
    private SQLAdapter $adapter;
    private SQLConnection $connection;
    private SQLModel $sourceModel;
    /** @var Set<SQLEntity> */
    private Set $removedEntities;
    /** @var Set<SQLManyToMany> */
    private Set $removedManyToMany;
    /** @var Set<SQLColumn> */
    private Set $removedColumns;
    /** @var Set<EntityMapping> */
    private Set $addedEntityMappings;
    /** @var Set<EntityMapping> */
    private Set $removedEntityMappings;
    /** @var Set<EntityMapping> */
    private Set $copiedEntityMappings;
    /** @var Set<EntityMapping> */
    private Set $transformedEntityMappings;
    /** @var Set<SQLStatement> */
    private Set $createIndexStatements;

    /**
     * @throws Exception
     */
    public function __construct(public readonly SQLCore $store, public readonly SQLModel $destinationModel, public readonly MappingModel $mappingModel)
    {
        $this->connection = $this->store->schemaValidationConnection;
        $this->adapter = $this->connection->adapter ?? fatal_error("SQL adapter cannot be null");
        $this->sourceModel = new SQLModel($this->connection->cachedModel ?? fatal_error("SQL source model cannot be ull"), $this->store->configurationName);
        $this->removedEntities = new Set();
        $this->removedManyToMany = new Set();
        $this->removedColumns = new Set();
        $this->addedEntityMappings = new Set();
        $this->removedEntityMappings = new Set();
        $this->copiedEntityMappings = new Set();
        $this->transformedEntityMappings = new Set();
        $this->createIndexStatements = new Set();
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

    private function prepareEntityMappings(): void
    {
        foreach ($this->mappingModel->entityMappingsByName as $mapping) {
            if ($mapping->mappingType === EntityMappingType::addEntityMappingType) {
                $this->addedEntityMappings->insert($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::removeEntityMappingType) {
                $this->removedEntityMappings->insert($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::copyEntityMappingType) {
                $this->copiedEntityMappings->insert($mapping);
            } elseif ($mapping->mappingType === EntityMappingType::transformEntityMappingType) {
                $this->transformedEntityMappings->insert($mapping);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function processAddedEntityMappings(): void
    {
        foreach ($this->addedEntityMappings as $mapping) {
            if (!($destinationEntityName = $mapping->destinationEntityName)) {
                continue;
            }
            /** @var SQLEntity|null $destinationEntity */
            $destinationEntity = $this->destinationModel->entitiesByName[$destinationEntityName];
            if (!$destinationEntity) {
                continue;
            }
            $statement = $this->adapter->newCreateTableStatement($destinationEntity);
            $this->connection->execute($statement);
            if ($statement = $this->adapter->newCreateIndexesStatement($destinationEntity)) {
                $this->createIndexStatements->insert($statement);
            }
            foreach ($destinationEntity->manyToManyRelationships as $manyToManyRelationship) {
                $statement = $this->adapter->newCreateTableStatementForManyToMany($manyToManyRelationship);
                $this->connection->execute($statement);
                $this->createIndexStatements->insert($this->adapter->newCreateIndexesStatementForManyToMany($manyToManyRelationship));
            }
        }
    }

    private function processRemovedEntityMappings(): void
    {
        foreach ($this->removedEntityMappings as $mapping) {
            if (!($sourceEntityName = $mapping->sourceEntityName)) {
                continue;
            }
            /** @var SQLEntity|null $sourceEntity */
            $sourceEntity = $this->sourceModel->entitiesByName[$sourceEntityName];
            if (!$sourceEntity) {
                continue;
            }
            $this->removedEntities->insert($sourceEntity);
        }
    }

    /**
     * @throws Exception
     */
    private function processCopiedEntityMappings(): void
    {
        foreach ($this->copiedEntityMappings as $mapping) {
            if (!($entities = $this->entities($mapping))) {
                continue;
            }
            [$sourceEntity, $destinationEntity] = $entities;
            foreach ($sourceEntity->indexes as $index) {
                if (!$destinationEntity->indexes->containsElement($index)) {
                    $this->connection->execute(SQLStatement::merging($index->dropTableStatements));
                }
            }
            foreach ($destinationEntity->indexes as $index) {
                if (!$sourceEntity->indexes->containsElement($index)) {
                    $this->createIndexStatements->formUnion($index->createTableStatements);
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function updateEntityKey(SQLEntity $sourceEntity, SQLEntity $destinationEntity): void
    {
        $request = new BatchUpdateRequest($destinationEntity->entityDescription);
        $request->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($sourceEntity->entityKey->columnName), Expression::expressionForConstantValue($sourceEntity->entityKey->defaultValue));
        $request->propertiesToUpdate = new Dictionary([$destinationEntity->entityKey->columnName => $destinationEntity->entityKey->defaultValue]);
        $requestContext = new SQLBatchUpdateRequestContext($request, new ManagedObjectContext(), $this->adapter->sqlCore);
        $requestContext->executeRequestUsingConnection($this->connection);
        if ($statement = $this->adapter->newModifyColumnStatement($destinationEntity->entityKey, $destinationEntity->primaryKey)) {
            $this->connection->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    private function prepareTransformedEntityMappings(): void
    {
        foreach ($this->transformedEntityMappings as $mapping) {
            if (!($entities = $this->entities($mapping))) {
                continue;
            }
            [$sourceEntity, $destinationEntity] = $entities;
            if (!($destinationSubentity = $destinationEntity->subentities->first(fn(SQLEntity $subentity): bool => $subentity->entityDescription->renamingIdentifier !== $subentity->entityDescription->name))) {
                continue;
            }
            if (!($sourceSubentity = $sourceEntity->subentities->first(fn(SQLEntity $subentity): bool => $subentity->entityDescription->name === $destinationSubentity->entityDescription->renamingIdentifier))) {
                continue;
            }
            $this->updateEntityKey($sourceSubentity, $destinationSubentity);
            /** @var ArrayClass<SQLToMany> $toManyRelationships */
            $toManyRelationships = $destinationSubentity->entitySpecificRelationships->filter(fn(SQLRelationship $relationship): bool => $relationship instanceof SQLToMany);
            foreach ($toManyRelationships as $toManyRelationship) {
                if (!($toMany = $sourceEntity->toManyRelationships->first(fn(SQLToMany $toMany): bool => $toMany->name === $toManyRelationship->name))) {
                    continue;
                }
                $statement = $this->adapter->newDropIndexStatementForForeignKey($toMany->inverseToOne->foreignKey);
                $this->connection->execute($statement);
                if (!($statement = $this->adapter->newRenameColumnStatement($toMany->inverseToOne->foreignKey, $toManyRelationship->inverseToOne->foreignKey))) {
                    continue;
                }
                $this->connection->execute($statement);
                $statement = $this->adapter->newCreateIndexStatementForForeignKey($toManyRelationship->inverseToOne->foreignKey);
                $this->connection->execute($statement);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function processTransformedEntityMappings(): void
    {
        foreach ($this->transformedEntityMappings as $mapping) {
            if (!($entities = $this->entities($mapping))) {
                continue;
            }
            [$sourceEntity, $destinationEntity] = $entities;
            if ($sourceEntity->tableName !== $destinationEntity->tableName && !$this->sourceModel->entitiesByName[$destinationEntity->tableName]) {
                $statement = $this->adapter->newRenameTableStatement($sourceEntity, $destinationEntity);
                $this->connection->execute($statement);
                $this->updateEntityKey($sourceEntity, $destinationEntity);
                foreach ($sourceEntity->toManyRelationships as $toManyRelationship) {
                    if (!($toMany = $destinationEntity->toManyRelationships->first(fn(SQLToMany $toMany): bool => $toMany->name === $toManyRelationship->name))) {
                        continue;
                    }
                    $statement = $this->adapter->newDropIndexStatementForForeignKey($toManyRelationship->inverseToOne->foreignKey);
                    $this->connection->execute($statement);
                    if (!($statement = $this->adapter->newRenameColumnStatement($toManyRelationship->inverseToOne->foreignKey, $toMany->inverseToOne->foreignKey))) {
                        continue;
                    }
                    $this->connection->execute($statement);
                    $statement = $this->adapter->newCreateIndexStatementForForeignKey($toMany->inverseToOne->foreignKey);
                    $this->connection->execute($statement);
                }
            }
            /** @var Set<SQLColumn> $columnsToCreate */
            $columnsToCreate = new Set($sourceEntity->columnsToCreate);
            $columnsToCreate->formUnion($destinationEntity->columnsToCreate);
            $properties = $sourceEntity->persistentProperties;
            foreach ($properties as $source) {
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
                        if ($source->name !== $destination->name && ($statement = $this->adapter->newRenameColumnStatement($source, $destination))) {
                            $this->connection->execute($statement);
                        }
                        if ($source->isCompositeAttribute !== $destination->isCompositeAttribute) {
                            if ($attributes = $sourceEntity->byMappingByCompositeNameAssociationTable[$source->name]?->values) {
                                $this->removedColumns->formUnion($attributes);
                            } else {
                                $this->removedColumns->insert($source);
                            }
                        } elseif (($source->sqlType !== $destination->sqlType || $source->isOptional !== $destination->isOptional || $source->isUnique !== $destination->isUnique || $source->minValue !== $destination->minValue || $source->maxValue !== $destination->maxValue || $source->defaultValue !== $destination->defaultValue || ($source->isDerivedAttribute !== $destination->isDerivedAttribute) || ($source->isDerivedAttribute && $destination->isDerivedAttribute && (string)$source->derivationExpression !== (string)$destination->derivationExpression))) {
                            if ($destination->isDerivedAttribute && ($statement = $this->adapter->newCreateColumnStatement($destination, $destinationEntity->columnAfter($destination)))) {
                                $this->connection->execute($statement);
                            } elseif ($statement = $this->adapter->newModifyColumnStatement($source, $destinationEntity->columnAfter($destination))) {
                                $this->connection->execute($statement);
                            }
                        }
                        if (!$source->isTransient && $destination->isTransient) {
                            $this->removedColumns->insert($source);
                        }
                    } elseif ($source instanceof SQLForeignKey && $destination instanceof SQLForeignKey) {
                        if ($source->toOneRelationship->relationshipDescription->deleteRule !== $destination->toOneRelationship->relationshipDescription->deleteRule) {
                            if ($source->columnName === $destination->columnName) {
                                $statement = $this->adapter->newDropIndexStatementForForeignKey($source);
                                $this->connection->execute($statement);
                            }
                            $statement = $this->adapter->newCreateIndexStatementForForeignKey($destination);
                            $this->connection->execute($statement);
                        }
                    } elseif ($source instanceof SQLRelationship && $destination instanceof SQLRelationship) {
                        if ($source instanceof $destination) {
                            if ($source instanceof SQLToMany && $destination instanceof SQLToMany && $source->relationshipDescription->deleteRule !== $destination->relationshipDescription->deleteRule) {
                                $statement = $this->adapter->newDropIndexStatementForForeignKey($source->inverseToOne->foreignKey);
                                $this->connection->execute($statement);
                                $statement = $this->adapter->newCreateIndexStatementForForeignKey($destination->inverseToOne->foreignKey);
                                $this->connection->execute($statement);
                            }
                        } else {
                            if ($source instanceof SQLManyToMany) {
                                $this->removedManyToMany->insert($source);
                            }
                            if ($destination instanceof SQLManyToMany) {
                                $statement = $this->adapter->newCreateTableStatementForManyToMany($destination);
                                $this->connection->execute($statement);
                                $this->createIndexStatements->insert($this->adapter->newCreateIndexesStatementForManyToMany($destination));
                            } elseif ($destination instanceof SQLToMany) {
                                if ($statement = $this->adapter->newCreateColumnStatement($destination->inverseToOne->foreignKey)) {
                                    $this->connection->execute($statement);
                                }
                            }
                        }
                    }
                } elseif ($source instanceof SQLAttribute || $source instanceof SQLForeignKey) {
                    if ($sourceEntity->isEqual($destinationEntity) && !$destinationEntity->properties->containsElement($source)) {
                        if ($attributes = $sourceEntity->byMappingByCompositeNameAssociationTable[$source->name]?->values) {
                            $this->removedColumns->formUnion($attributes);
                        } else {
                            $this->removedColumns->insert($source);
                        }
                    }
                } elseif ($source instanceof SQLManyToMany) {
                    $this->removedManyToMany->insert($source);
                }
            }
            $properties = $destinationEntity->persistentProperties;
            foreach ($properties as $property) {
                if ($property instanceof SQLAttribute || $property instanceof SQLForeignKey) {
                    if ($attributes = $destinationEntity->byMappingByCompositeNameAssociationTable[$property->name]?->values) {
                        foreach ($attributes as $attribute) {
                            if ($statement = $this->adapter->newCreateColumnStatement($attribute)) {
                                $this->connection->execute($statement);
                            }
                        }
                    } elseif ($statement = $this->adapter->newCreateColumnStatement($property)) {
                        $this->connection->execute($statement);
                    }
                } elseif ($property instanceof SQLManyToMany) {
                    $statement = $this->adapter->newCreateTableStatementForManyToMany($property);
                    $this->connection->execute($statement);
                    $this->createIndexStatements->insert($this->adapter->newCreateIndexesStatementForManyToMany($property));
                }
            }
            foreach ($destinationEntity->indexes as $destinationIndex) {
                if (!$sourceEntity->indexes->containsElement($destinationIndex)) {
                    $this->createIndexStatements->formUnion($destinationIndex->createTableStatements);
                }
            }
            foreach ($properties as $property) {
                if ($property instanceof SQLAttribute && ($statement = $this->adapter->newModifyColumnStatement($property, $destinationEntity->columnAfter($property)))) {
                    $this->connection->execute($statement);
                }
            }
            foreach ($sourceEntity->indexes as $sourceIndex) {
                if (!$destinationEntity->indexes->containsElement($sourceIndex)) {
                    $this->connection->execute(SQLStatement::merging($sourceIndex->dropTableStatements));
                }
            }
            $properties = $destinationEntity->persistentProperties;
            foreach ($properties as $property) {
                if ($property instanceof SQLToMany) {
                    if ($statement = $this->adapter->newCreateColumnStatement($property->inverseToOne->foreignKey)) {
                        $this->connection->execute($statement);
                    }
                    $statement = $this->adapter->newCreateIndexStatementForForeignKey($property->inverseToOne->foreignKey);
                    $this->connection->execute($statement);
                } elseif ($property instanceof SQLToOne) {
                    if ($statement = $this->adapter->newCreateColumnStatement($property->foreignKey)) {
                        $this->connection->execute($statement);
                    }
                    $statement = $this->adapter->newCreateIndexStatementForForeignKey($property->foreignKey);
                    $this->connection->execute($statement);
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function recreateIndexes(): void
    {
        foreach ($this->createIndexStatements as $statement) {
            $this->connection->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    public function perform(): void
    {
        $this->prepareEntityMappings();
        $this->processAddedEntityMappings();
        $this->processRemovedEntityMappings();
        $this->processCopiedEntityMappings();
        $this->prepareTransformedEntityMappings();
        $this->processTransformedEntityMappings();
        $this->recreateIndexes();
        $this->removeUnusedRelationships();
        $this->removeUnusedColumns();
        $this->removeUnusedEntities();
    }

    /**
     * @throws Exception
     */
    private function removeUnusedRelationships(): void
    {
        foreach ($this->removedManyToMany as $manyToMany) {
            $statement = $this->adapter->newDropTableStatementForManyToMany($manyToMany);
            $this->connection->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    private function removeUnusedColumns(): void
    {
        $removedColumns = $this->removedColumns->sort(fn(SQLColumn $c1, SQLColumn $c2): int => $c1 instanceof SQLAttribute && $c1->isDerivedAttribute ? ComparisonResult::orderedAscending->value : ComparisonResult::orderedDescending->value);
        foreach ($removedColumns as $removedColumn) {
            if ($removedColumn instanceof SQLForeignKey) {
                $this->connection->execute($this->adapter->newDropIndexStatementForForeignKey($removedColumn));
            }
            $this->connection->execute($this->adapter->newDropColumnStatement($removedColumn));
        }
    }

    /**
     * @throws Exception
     */
    private function removeUnusedEntities(): void
    {
        foreach ($this->removedEntities as $entity) {
            /** @var ArrayClass<SQLForeignKey> $foreignKeys  */
            $foreignKeys = $entity->toManyRelationships->flatMap(fn(SQLToMany $many): ArrayClass => $many->destinationEntity->foreignKeyColumns->filter(fn(SQLForeignKey $foreignKey): bool => $foreignKey->toOneRelationship->isEqual($many->inverseToOne)));
            foreach ($foreignKeys as $foreignKey) {
                $statement = $this->adapter->newDropIndexStatementForForeignKey($foreignKey);
                $this->connection->execute($statement);
            }
            foreach ($entity->manyToManyRelationships as $manyToManyRelationship) {
                $statement = $this->adapter->newDropIndexesStatementForManyToMany($manyToManyRelationship);
                $this->connection->execute($statement);
                $statement = $this->adapter->newDropTableStatementForManyToMany($manyToManyRelationship);
                $this->connection->execute($statement);
            }
            if ($statement = $this->adapter->newDropIndexesStatement($entity)) {
                $this->connection->execute($statement);
            }
        }
        foreach ($this->removedEntities as $entity) {
            $statement = $this->adapter->newDropTableStatement($entity);
            $this->connection->execute($statement);
        }
    }

    /**
     * @throws Exception
     */
    public function disconnect(): void
    {
        $this->connection->saveCachedModel($this->destinationModel);
        $this->connection->disconnect();
    }
}
