<?php

/** @noinspection PhpInternalEntityUsedInspection */

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 22:13
 */

namespace Sabatier\CoreData;

use Closure;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonResult;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyValueOperator;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\ComparisonPredicateModifier;
use Sabatier\Foundation\Predicates\ComparisonPredicateOptions;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicateLogicalType;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionOperator;
use Sabatier\Foundation\Predicates\ExpressionOperatorType;
use Sabatier\Foundation\Predicates\ExpressionType;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\Sequence;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\is_equal;
use function Sabatier\Foundation\kvc_components;
use function Sabatier\Foundation\typeof;

/** @internal */
class SQLGenerator extends ObjectClass
{
    private string $string;
    private string $selectList;
    private string $joinClause;
    private string $whereClause;
    private string $groupByClause;
    private string $havingClause;
    private string $orderByClause;
    private FetchRequest $request {
        get => $this->request ??= $this->request();
    }
    private SQLEntity $entity {
        get => $this->entity ??= $this->entity();
    }
    /** @var ArrayClass<mixed> */
    public ArrayClass $arguments {
        get => $this->arguments ??= new ArrayClass();
    }
    public ?SQLStatement $statement {
        get => $this->statement ??= $this->statement();
    }
    private bool $useDistinct = false;
    private string $keyValueOperator = KeyValueOperator::countKeyValueOperator;
    public bool $autoDistinct = true;
    public bool $raisesForNotApplicableKeys = true;

    public function __construct(public readonly SQLStoreRequestContext $requestContext)
    {
    }

    private function request(): FetchRequest
    {
        if ($this->requestContext instanceof SQLBatchUpdateRequestContext) {
            return $this->requestContext->fetchContext->request;
        }
        if ($this->requestContext instanceof SQLBatchDeleteRequestContext) {
            return $this->requestContext->fetchContext->request;
        }
        if ($this->requestContext instanceof SQLFetchRequestContext) {
            return $this->requestContext->request;
        }
        fatal_error("Invalid SQL request context");
    }

    private function entity(): SQLEntity
    {
        if ($this->requestContext instanceof SQLBatchInsertRequestContext) {
            return $this->requestContext->sqlEntityForInsertRequest;
        }
        if ($this->requestContext instanceof SQLBatchUpdateRequestContext) {
            return $this->requestContext->fetchContext->sqlEntityForFetchRequest;
        }
        if ($this->requestContext instanceof SQLBatchDeleteRequestContext) {
            return $this->requestContext->fetchContext->sqlEntityForFetchRequest;
        }
        if ($this->requestContext instanceof SQLFetchRequestContext) {
            return $this->requestContext->sqlEntityForFetchRequest;
        }
        fatal_error("Invalid SQL request context");
    }

    private function statement(): ?SQLStatement
    {
        if ($this->requestContext instanceof SQLBatchInsertRequestContext || $this->requestContext instanceof SQLBatchUpdateRequestContext || $this->requestContext instanceof SQLBatchDeleteRequestContext || $this->requestContext instanceof SQLFetchRequestContext) {
            return $this->newSQLStatementForPersistentStoreRequest();
        }
        if ($this->requestContext instanceof SQLSaveChangesRequestContext) {
            return $this->newSQLStatementForSaveChangesRequestContext();
        }
        return null;
    }

    private function newSQLStatementForPersistentStoreRequest(): SQLStatement
    {
        $this->startSQL($this->requestContext->persistentStoreRequest);
        return new SQLStatement($this->string, $this->arguments);
    }

    private function newSQLStatementForSaveInsertChanges(SQLEntity $entity, ArrayClass $insertedObjects): SQLStatement
    {
        $this->prepareInsertStatement($entity, $insertedObjects);
        return new SQLStatement($this->string, $this->arguments);
    }

    private function newSQLStatementForSaveUpdateChanges(SQLEntity $entity, ArrayClass $updatedObjects): SQLStatement
    {
        $this->prepareUpdateStatement($entity, $updatedObjects);
        return new SQLStatement($this->string, $this->arguments);
    }

    private function newSQLStatementForSaveDeleteChanges(SQLEntity $entity, ArrayClass $deletedObjects): SQLStatement
    {
        $this->prepareDeleteStatement($entity, $deletedObjects);
        return new SQLStatement($this->string, $this->arguments);
    }

    private function newSQLStatementForSaveChangesRequestContext(): ?SQLStatement
    {
        /** @var SQLSaveChangesRequestContext $requestContext */
        $requestContext = $this->requestContext;
        /** @var ArrayClass<SQLStatement> $statements */
        $statements = new ArrayClass();
        $model = $requestContext->sqlCore->model;
        if ($insertedObjects = $requestContext->request->insertedObjects) {
            $dictionary = $this->groupedObjects($insertedObjects);
            foreach ($dictionary as $key => $value) {
                if (!$value->isEmpty && ($entity = $model->entity($key))) {
                    $statements->append($this->newSQLStatementForSaveInsertChanges($entity, $value));
                }
            }
        }
        if ($updatedObjects = $requestContext->request->updatedObjects) {
            $dictionary = $this->groupedObjects($updatedObjects);
            foreach ($dictionary as $key => $value) {
                if (!$value->isEmpty && ($entity = $model->entity($key))) {
                    $statements->append($this->newSQLStatementForSaveUpdateChanges($entity, $value));
                }
            }
        }
        if ($deletedObjects = $requestContext->request->deletedObjects) {
            $dictionary = $this->groupedObjects($deletedObjects);
            foreach ($dictionary as $key => $value) {
                if (!$value->isEmpty && ($entity = $model->entity($key))) {
                    $statements->append($this->newSQLStatementForSaveDeleteChanges($entity, $entity->entityDescription->isPersistentHistoryEntity ? $value->map(fn(PersistentHistoryTransaction $transaction): int => $transaction->transactionNumber) : $value->map(fn(ManagedObject $object): int|string => $object->objectID->referenceObject)));
                }
            }
        }
        if ($statements->isEmpty) {
            return null;
        }
        /** @psalm-suppress RedundantCondition */
        if (SS_COREDATA_DISABLE_FOREIGN_KEY_CHECKS) :
            if ($statements->contains(fn(SQLStatement $statement): bool => str_starts_with($statement->string, "INSERT"))) {
                $statements->insertAt(new SQLStatement("/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */"), 0);
                $statements->append(new SQLStatement("/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */"));
            }
        endif;
        return SQLStatement::merging($statements);
    }

    private function compound(EntityDescription $entity, ?Predicate $predicate): ?Predicate
    {
        /** @var  EntityDescription $rootEntity */
        $rootEntity = $entity->isRootEntity ? $entity : $entity->rootEntity;
        $subentities = $entity->managedObjectModel->flatten($rootEntity->subentities);
        if (!$entity->isAbstract && !$subentities->isEmpty) {
            $mandatory = new ComparisonPredicate(Expression::expressionForKeyPath($this->entity->entityKey->columnName), Expression::expressionForConstantValue($entity->name));
            if ($predicate) {
                $predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([$predicate, $mandatory]));
            } else {
                $predicate = $mandatory;
            }
        }
        return $predicate;
    }

    private function resetSQL(): void
    {
        $this->selectList = "";
        $this->joinClause = "";
        $this->whereClause = "";
        $this->groupByClause = "";
        $this->havingClause = "";
        $this->orderByClause = "";
        $this->string = $this->selectList;
        $this->arguments->removeAll();
    }

    private function startSQL(PersistentStoreRequest $request): void
    {
        $this->resetSQL();
        if ($request instanceof FetchRequest) {
            $entity = $request->entity;
            /** @var ArrayClass<PropertyDescription> $propertiesToGroupBy */
            $propertiesToGroupBy = $request->propertiesToGroupBy?->compactMap(fn(PropertyDescription|string $property): ?PropertyDescription => $property instanceof PropertyDescription ? $property : $entity->propertiesByName[$property]) ?? new ArrayClass();
            if (!$propertiesToGroupBy->isEmpty && $request->resultType !== FetchRequestResultType::dictionaryResultType) {
                fatal_error(sprintf("Invalid fetch request: GROUP BY requires %s, %s given", human_readable_value(FetchRequestResultType::dictionaryResultType), human_readable_value($request->resultType)));
            }
            $this->useDistinct = $request->returnsDistinctResults;
            if (!$this->useDistinct && $this->autoDistinct) {
                $this->useDistinct = ($request->propertiesToFetch?->compactMap(fn(PropertyDescription|string $property): ?PropertyDescription => $property instanceof PropertyDescription ? $property : $entity->propertiesByName[$property])?->contains(fn(PropertyDescription $property): bool => $property instanceof RelationshipDescription)) || ($request->serialization->contains(fn(mixed $e): bool => $e instanceof Dictionary));
            }
            $this->prepareSelectStatementWithFetchRequest($request);
            $this->prepareJoinStatementsForPredicateAndRelationships();
            $predicate = $this->compound($entity, $request->predicate);
            if ($predicate) {
                $this->appendWhereClauseToSQL();
                $this->preparePredicate($predicate, $this->whereClause);
            }
            $this->appendFromClauseToSQL();
            $this->appendSQL($this->selectList);
            $this->appendSQL($this->joinClause);
            $this->appendSQL($this->whereClause);
            if (!$propertiesToGroupBy->isEmpty) {
                $this->buildGroupByClause($propertiesToGroupBy);
                $this->appendSQL($this->groupByClause);
                if ($havingPredicate = $request->havingPredicate) {
                    $this->appendHavingClauseToSQL();
                    $this->preparePredicate($havingPredicate, $this->havingClause);
                }
                $this->appendSQL($this->havingClause);
            }
            if ($request->resultType !== FetchRequestResultType::countResultType) {
                $this->buildOrderByClause($request->sortDescriptors?->filter(fn(SortDescriptor $descriptor): bool => match ($descriptor->key) {
                    SQLEntity::entityKeyName, SQLEntity::primaryKeyName => true,
                    default => !$entity->propertiesByName[$descriptor->key]?->isTransient
                }) ?? new ArrayClass());
                $this->appendSQL($this->orderByClause);
            }
            if ($request->fetchLimit) {
                $this->appendLimitClauseToSQL($request->fetchLimit);
            }
            if ($request->fetchOffset) {
                $this->appendOffsetClauseToSQL($request->fetchOffset);
            }
        } elseif ($request instanceof BatchInsertRequest) {
            $this->prepareStatementForBatchInsertRequest();
        } elseif ($request instanceof BatchUpdateRequest) {
            $this->prepareStatementForBatchUpdateRequest();
            $this->prepareJoinStatementsForPredicateAndRelationships();
            $this->appendSQL($this->joinClause);
            $this->appendSetStatementForBatchUpdateRequest($request);
            if ($predicate = $this->compound($request->entity, $request->predicate)) {
                $this->appendWhereClauseToSQL();
                $this->preparePredicate($predicate, $this->whereClause);
                $this->appendSQL($this->whereClause);
            }
        } elseif ($request instanceof BatchDeleteRequest) {
            $this->prepareStatementForBatchDeleteRequest($request);
            $this->prepareJoinStatementsForPredicateAndRelationships();
            $this->appendSQL($this->joinClause);
            if ($predicate = $request->fetchRequest->predicate) {
                $this->appendWhereClauseToSQL();
                $this->preparePredicate($predicate, $this->whereClause);
                $this->appendSQL($this->whereClause);
            }
        }
        $this->endSQL();
    }

    private function endSQL(): void
    {
        $delimiter = ";";
        if (str_ends_with($this->string, $delimiter)) {
            $this->string = rtrim($this->string, ";");
        }
    }

    private function appendSQL(string $sql): void
    {
        $this->string .= $sql;
    }

    private function appendFromClauseToSQL(): void
    {
        $this->selectList .= " FROM ";
        $this->selectList .= "`{$this->entity->tableName}`";
    }

    private function appendJoinClauseToSQL(): void
    {
        $this->joinClause .= " LEFT JOIN ";
    }

    private function appendWhereClauseToSQL(): void
    {
        $this->whereClause .= " WHERE ";
    }

    private function appendGroupByClauseToSQL(): void
    {
        $this->groupByClause .= " GROUP BY ";
    }

    private function appendHavingClauseToSQL(): void
    {
        $this->havingClause .= " HAVING ";
    }

    private function appendOrderByClauseToSQL(): void
    {
        $this->orderByClause .= " ORDER BY ";
    }

    private function appendLimitClauseToSQL(int $limit): void
    {
        $this->appendSQL(" LIMIT $limit");
    }

    private function appendOffsetClauseToSQL(int $offset): void
    {
        $this->appendSQL(" OFFSET $offset");
    }

    private function appendSelectListToSQLForRequest(FetchRequest $request): void
    {
        $entity = $this->entity;
        /** @var Set<string> $columnNames */
        $columnNames = new Set();
        if ($this->keyValueOperator === KeyValueOperator::countKeyValueOperator) {
            $columnNames->append("$entity->tableName.{$entity->primaryKey->columnName}");
        }
        $appendInferredColumnNames = true;
        if (($request->resultType === FetchRequestResultType::countResultType && $this->keyValueOperator === KeyValueOperator::countKeyValueOperator) || $request->returnsObjectsAsFaults || !$request->includesPropertyValues) {
            $appendInferredColumnNames = false;
        }
        if ($appendInferredColumnNames) {
            if (!$entity->entityDescription->isPersistentHistoryEntity && $request->resultType !== FetchRequestResultType::countResultType) {
                $columnNames->append("$entity->tableName.{$entity->entityKey->columnName}");
            }
            $keys = $request->serialization->keys->filter(function (string $key) use ($entity): bool {
                /** @var SQLProperty $property */
                $property = $entity->propertiesByName[$key] ?? fatal_error("$entity->tableName does not contains a property named \"$key\"");
                return !$property->isTransient;
            });
            /** @var ArrayClass<string|PropertyDescription> $properties */
            $properties = new ArrayClass();
            if ($propertiesToFetch = $request->propertiesToFetch) {
                $properties->appendContentsOf($propertiesToFetch->filter(fn(PropertyDescription|string $property): bool => $property instanceof PropertyDescription ? !$property->isTransient && !$keys->containsElement($property->name) : !$keys->containsElement($property)));
            }
            $properties->appendContentsOf($keys->filter(fn(string $key): bool => $request->entity->attributesByName->contains(fn(AttributeDescription $attribute): bool => !$attribute->isTransient && $attribute->name === $key)));
            /** @psalm-suppress InvalidArgument */
            $columnNames->appendContentsOf($properties->compactMap(fn(PropertyDescription|string $property): ?PropertyDescription => is_string($property) ? $entity->attributes->first(fn(SQLAttribute $attribute): bool => !$attribute->isCompositeAttribute && !$attribute->isTransient && $attribute->name === $property)?->attributeDescription : (($property instanceof AttributeDescription && !$property instanceof CompositeAttributeDescription) || $property instanceof ExpressionDescription ? $property : null))->map(function (PropertyDescription $property) use ($entity): string {
                if ($property instanceof AttributeDescription) {
                    if ($property instanceof DerivedAttributeDescription && $property->derivationExpression?->usesKVC) {
                        return "{$this->buildDerivationExpression($property->derivationExpression)} AS $property->name";
                    }
                } elseif ($property instanceof ExpressionDescription) {
                    /** @var Expression $expression */
                    $expression = $property->expression ?? fatal_error("Invalid argument: invalid property $property");
                    return match ($expression->expressionType) {
                        ExpressionType::function => "{$this->buildFunctionExpression($expression)} AS $property->name",
                        ExpressionType::conditional => "{$this->buildConditionalExpression($expression)} AS $property->name",
                        default => fatal_error("Invalid argument: unsupported expression $expression")
                    };
                }
                return "$entity->tableName.$property->name";
            }));
        }
        $columnNames->appendContentsOf($entity->byMappingByCompositeNameAssociationTable->values->flatMap(fn(Dictionary $dictionary): ArrayClass => $dictionary->values->map(fn(SQLAttribute $attribute): string => "$entity->tableName.$attribute->name")));
        $this->selectList .= $columnNames->join(", ");
    }

    private function prepareSelectStatementWithFetchRequest(FetchRequest $request): void
    {
        $this->selectList = "SELECT ";
        if ($request->resultType === FetchRequestResultType::countResultType) {
            $this->selectList .= strtoupper($this->keyValueOperator);
            $this->selectList .= "(";
        }
        if ($this->useDistinct) {
            $this->selectList .= "DISTINCT ";
        }
        $this->appendSelectListToSQLForRequest($request);
        if ($request->resultType === FetchRequestResultType::countResultType) {
            $this->selectList .= ")";
        }
    }

    private function prepareJoinStatementsForPredicateAndRelationships(): void
    {
        $raisesForNotApplicableKeys = $this->raisesForNotApplicableKeys;
        $this->raisesForNotApplicableKeys = false;
        $expressions = $this->keyPathExpressionsForFetchRequestSerialization()->union($this->keyPathExpressionsForFetchRequestPredicate());
        foreach ($expressions as $expression) {
            $this->appendJoinsForRelationships($this->relationshipsFromKeyPathExpression($expression));
        }
        $this->joinClause = new Set(explode(" LEFT JOIN ", $this->joinClause))->join(" LEFT JOIN ");
        $this->raisesForNotApplicableKeys = $raisesForNotApplicableKeys;
    }

    private function appendJoinDestinationEntity(SQLEntity $destinationEntity, string $destinationPath): void
    {
        if ($destinationEntity->isAbstract || $destinationPath === "") {
            return;
        }
        /** @var EntityDescription $rootEntity */
        $rootEntity = $destinationEntity->isRootEntity ? $destinationEntity->entityDescription : $destinationEntity->rootEntity?->entityDescription;
        $subentities = $destinationEntity->entityDescription->managedObjectModel->flatten($rootEntity->subentities);
        if ($subentities->count <= 1) {
            return;
        }
        $this->joinClause .= " AND ";
        $this->joinClause .= "$destinationPath.{$destinationEntity->entityKey->columnName} = '{$destinationEntity->entityDescription->name}'";
    }

    private function addJoinForToOneRelationship(SQLToOne $toOne, string $sourcePath = "", string $destinationPath = ""): void
    {
        $sourceEntity = $toOne->entity;
        $inverseRelationship = $toOne->inverseRelationship;
        $destinationEntity = $toOne->destinationEntity;
        $columnName = $inverseRelationship instanceof SQLToOne ? $inverseRelationship->foreignKey->columnName : $destinationEntity->primaryKey->columnName;
        if ($sourcePath === "") {
            $sourcePath = $sourceEntity->tableName;
        }
        if ($destinationPath === "") {
            $destinationPath = "{$sourceEntity->tableName}_$toOne->name";
        }
        $this->appendJoinClauseToSQL();
        $this->joinClause .= "`$destinationEntity->tableName` AS $destinationPath ON $destinationPath.$columnName = ";
        $this->joinClause .= $inverseRelationship instanceof SQLToOne ? "$sourcePath.{$sourceEntity->primaryKey->columnName}" : "$sourcePath.{$toOne->foreignKey->columnName}";
        if (!$sourceEntity->entityDescription->isPersistentHistoryEntity) {
            $this->appendJoinDestinationEntity($destinationEntity, $destinationPath);
        }
    }

    private function addJoinForToManyRelationship(SQLToMany $toMany, string $sourcePath = "", string $destinationPath = ""): void
    {
        $sourceEntity = $toMany->entity;
        $inverseToOne = $toMany->inverseToOne;
        $destinationEntity = $toMany->destinationEntity;
        if ($sourcePath === "") {
            $sourcePath = $sourceEntity->tableName;
        }
        if ($destinationPath === "") {
            $destinationPath = "{$sourceEntity->tableName}_$toMany->name";
        }
        $this->appendJoinClauseToSQL();
        $this->joinClause .= "`$destinationEntity->tableName` AS $destinationPath ON $destinationPath.{$inverseToOne->foreignKey->columnName} = ";
        $this->joinClause .= "$sourcePath.{$sourceEntity->primaryKey->columnName}";
        if (!$sourceEntity->entityDescription->isPersistentHistoryEntity) {
            $this->appendJoinDestinationEntity($destinationEntity, $destinationPath);
        }
    }

    private function addJoinForManyToManyRelationship(SQLManyToMany $manyToMany, string $sourcePath = "", string $destinationPath = ""): void
    {
        $correlationTableName = $manyToMany->correlationTableName;
        $inverseManyToMany = $manyToMany->inverseManyToMany;
        $sourceEntity = $inverseManyToMany->destinationEntity;
        if ($sourcePath === "") {
            $sourcePath = $sourceEntity->tableName;
        }
        $correlationTableAlias = "{$sourcePath}_$correlationTableName";
        $destinationEntity = $manyToMany->destinationEntity;
        $this->appendJoinClauseToSQL();
        $this->joinClause .= "`$correlationTableName` AS $correlationTableAlias";
        $this->joinClause .= " ON ";
        $this->joinClause .= "$correlationTableAlias.$manyToMany->inverseColumnName";
        $this->joinClause .= " = ";
        $this->joinClause .= "$sourcePath.{$sourceEntity->primaryKey->columnName}";
        $this->appendJoinClauseToSQL();
        $this->joinClause .= "`$destinationEntity->tableName` AS $destinationPath ON $correlationTableAlias.$manyToMany->columnName = $destinationPath.{$destinationEntity->primaryKey->columnName}";
        $this->appendJoinDestinationEntity($destinationEntity, $destinationPath);
    }

    /**
     * @param ArrayClass<SQLRelationship> $relationships
     */
    private function appendJoinsForRelationships(ArrayClass $relationships): void
    {
        $source = "";
        $entity = $this->entity;
        $cursor = $entity->tableName;
        $request = $this->request;
        $resultType = $request->resultType;
        $serialization = $request->serialization;
        /** @var SQLRelationship $relationship */
        foreach ($relationships as $relationship) {
            $name = $relationship->name;
            $destination = "{$cursor}_$name";
            if ($relationship instanceof SQLToOne) {
                $this->addJoinForToOneRelationship($relationship, $source, $destination);
            } elseif ($relationship instanceof SQLToMany) {
                $this->addJoinForToManyRelationship($relationship, $source, $destination);
            } elseif ($relationship instanceof SQLManyToMany) {
                $this->addJoinForManyToManyRelationship($relationship, $source, $destination);
            }
            $entity = $relationship->destinationEntity;
            if ($resultType !== FetchRequestResultType::countResultType) {
                $columnNames = $entity->columnsToFetch->map(fn(SQLColumn $column): string => "$destination.$column->columnName AS {$destination}_$column->columnName");
                $dictionary = $serialization[$name];
                if ($dictionary instanceof Dictionary) {
                    $serialization = clone $dictionary;
                    $serializationKeys = $serialization->keys->filter(function (string $key) use ($entity): bool {
                        /** @var SQLProperty $property */
                        $property = $entity->propertiesByName[$key] ?? fatal_error("$entity->tableName does not contains a property named \"$key\"");
                        return !$property->isTransient;
                    });
                    if (!$serializationKeys->containsElement($entity->primaryKey->columnName)) {
                        $serializationKeys->insertAt($entity->primaryKey->columnName, 0);
                    }
                    if (!$entity->entityDescription->isPersistentHistoryEntity && !$serializationKeys->containsElement($entity->entityKey->columnName)) {
                        $serializationKeys->insertAt($entity->entityKey->columnName, 1);
                    }
                    /** @var ArrayClass<string> $columnNames */
                    $columnNames = $serializationKeys->compactMap(function (string $key) use ($entity, $destination): ?string {
                        $property = $entity->propertiesByName[$key];
                        if ($property instanceof SQLEntityKey || $property instanceof SQLPrimaryKey) {
                            return "$destination.$property->columnName AS {$destination}_$property->columnName";
                        }
                        if ($property instanceof SQLAttribute) {
                            if (($expression = $property->derivationExpression) && $expression->usesKVC) {
                                $bk = $this->entity;
                                $this->entity = $entity;
                                $result = $this->buildDerivationExpression($expression, $destination);
                                $this->entity = $bk;
                                return "$result AS {$destination}_$property->columnName";
                            }
                            return "$destination.$property->columnName AS {$destination}_$property->columnName";
                        }
                        return null;
                    });
                }
                if (!$columnNames->isEmpty) {
                    $copy = clone $columnNames;
                    foreach ($copy as $columnName) {
                        if (str_contains($this->selectList, $columnName)) {
                            $columnNames->remove($columnName);
                            if (str_contains($columnName, "?")) {
                                $this->arguments->popFirst();
                            }
                        }
                    }
                    /** @psalm-suppress RedundantCondition */
                    if (!$columnNames->isEmpty) {
                        $this->selectList .= ", ";
                        $this->selectList .= $columnNames->join(", ");
                    }
                }
            }
            $source = $destination;
            $cursor .= "_";
            $cursor .= $name;
        }
    }

    /**
     * @param Dictionary<mixed>|null $serialization
     * @param string|null $parent
     * @param EntityDescription|null $entity
     * @return Set<Expression>
     */
    private function keyPathExpressionsForFetchRequestSerialization(?Dictionary $serialization = null, ?string $parent = null, ?EntityDescription $entity = null): Set
    {
        /** @var Set<Expression> $expressions */
        $expressions = new Set();
        if ($parent !== null) {
            $parent = "$parent.";
        }
        $request = $this->request;
        $entity ??= $request->entity;
        $serialization ??= $request->serialization;
        foreach ($serialization as $key => $value) {
            $property = $entity->propertiesByName[$key];
            if ($property instanceof RelationshipDescription) {
                /** @psalm-suppress PossiblyNullOperand */
                $current = $parent . $key;
                $expressions->append(Expression::expressionForKeyPath($current));
                $expressions->appendContentsOf($this->keyPathExpressionsForFetchRequestSerialization($value, $current, $property->destinationEntity));
            }
        }
        return $expressions;
    }

    /**
     * @param Predicate|null $predicate
     * @return Set<Expression>
     */
    private function keyPathExpressionsForFetchRequestPredicate(?Predicate $predicate = null): Set
    {
        /** @var Set<Expression> $expressions */
        $expressions = new Set();
        $predicate ??= $this->request->predicate;
        if ($predicate instanceof ComparisonPredicate) {
            if ($predicate->leftExpression->expressionType === ExpressionType::keyPath) {
                $expressions->append($predicate->leftExpression);
            }
            if ($predicate->rightExpression->expressionType === ExpressionType::keyPath) {
                $expressions->append($predicate->rightExpression);
            }
        } elseif ($predicate instanceof CompoundPredicate) {
            foreach ($predicate->subpredicates as $subpredicate) {
                $expressions->appendContentsOf($this->keyPathExpressionsForFetchRequestPredicate($subpredicate));
            }
        }
        return $expressions;
    }

    /**
     * @param Expression $expression
     * @param Closure(SQLProperty): bool|null $predicate
     * @return ArrayClass<SQLProperty>
     */
    private function propertiesFromKeyPathExpression(Expression $expression, ?Closure $predicate = null): ArrayClass
    {
        $predicate ??= fn(SQLProperty $property): bool => true;
        /** @var ArrayClass<SQLProperty> $properties */
        $properties = new ArrayClass();
        if ($expression->expressionType !== ExpressionType::keyPath) {
            return $properties;
        }
        if ($expression->usesKVC) {
            return $properties;
        }
        $entity = $this->entity;
        $keys = new Set(explode(".", $expression->description));
        foreach ($keys as $key) {
            $property = $entity->propertiesByName[$key];
            if ($property) {
                if ($property instanceof SQLAttribute && $property->isCompositeAttribute) {
                    if ($predicate($property)) {
                        $properties[] = $entity->byMappingByCompositeNameAssociationTable->valueForKeyPath($expression->keyPath);
                    }
                    break;
                }
                if ($property instanceof SQLRelationship) {
                    $entity = $property->destinationEntity;
                }
                if ($predicate($property)) {
                    $properties[] = $property;
                }
                continue;
            }
            if ($this->raisesForNotApplicableKeys) {
                fatal_error("$entity->tableName does not contains a property named \"$key\"");
            }
            break;
        }
        return $properties;
    }

    /**
     * @param Expression $expression
     * @return ArrayClass<SQLRelationship>
     */
    private function relationshipsFromKeyPathExpression(Expression $expression): ArrayClass
    {
        /** @var ArrayClass<SQLRelationship> */
        return $this->propertiesFromKeyPathExpression($expression, fn(SQLProperty $property): bool => $property instanceof SQLRelationship);
    }

    private function isNullExpression(Expression $expression): bool
    {
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        return match ($expression->expressionType) {
            ExpressionType::constantValue => Nil::nil()->isEqual($expression->constantValue),
            ExpressionType::keyPath => Nil::nil()->isEqual(new Value((string)$expression)) ? fatal_error("Invalid argument: invalid expression $expression") : false,
            default => false
        };
    }

    private function isKeyPathExpression(Expression $expression): bool
    {
        return $expression->expressionType === ExpressionType::keyPath;
    }

    private function buildKeyPathExpression(Expression $expression, ?bool &$isDeterministic = true): string
    {
        if ($expression->usesKVC) {
            return $this->buildDerivedKeyPathExpression($expression, null, $isDeterministic);
        }
        $tableName = $this->entity->tableName;
        $keyPath = $tableName;
        $destination = $tableName;
        $description = $expression->description;
        $properties = $this->propertiesFromKeyPathExpression($expression);
        $max = $properties->indexBefore($properties->endIndex);
        foreach ($properties as $idx => $property) {
            if ($property instanceof SQLPrimaryKey || $property instanceof SQLEntityKey || $property instanceof SQLAttribute || $property instanceof SQLForeignKey) {
                if ($property instanceof SQLAttribute && ($expression = $property->derivationExpression) && $expression->usesKVC) {
                    return $this->buildDerivationExpression($expression, $destination, $isDeterministic);
                }
                $keyPath .= ".";
                $keyPath .= $property->columnName;
            }
            if ($property instanceof SQLRelationship) {
                $keyPath .= "_";
                $keyPath .= $property->name;
                $destination .= "_";
                $destination .= $property->name;
                if ($property instanceof SQLToOne && ($idx === $max)) {
                    $keyPath .= ".";
                    $keyPath .= $property->destinationEntity->primaryKey->columnName;
                }
            }
        }
        if ($this->raisesForNotApplicableKeys && $keyPath === $tableName) {
            fatal_error("Failed to generate an alias for entity \"$tableName\", invalid key path \"$description\"");
        }
        return $keyPath;
    }

    private function buildClauseWithSimplePredicate(ComparisonPredicate $predicate, string &$clause): void
    {
        if ($predicate->predicateOperatorType == PredicateOperatorType::lessThan) {
            $this->prepareClauseWithSimplePredicate($predicate, $clause, "<");
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::lessThanOrEqualTo) {
            $this->prepareClauseWithSimplePredicate($predicate, $clause, "<=");
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::greaterThan) {
            $this->prepareClauseWithSimplePredicate($predicate, $clause, ">");
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::greaterThanOrEqualTo) {
            $this->prepareClauseWithSimplePredicate($predicate, $clause, ">=");
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::equalTo) {
            $this->prepareEqual($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::notEqualTo) {
            $this->prepareNotEqual($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::like) {
            $this->prepareLike($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::matches) {
            $this->prepareMatches($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::beginsWith) {
            $this->prepareBeginsWith($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::endsWith) {
            $this->prepareEndsWith($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::contains) {
            $this->prepareContains($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::in) {
            $this->prepareIn($predicate, $clause);
        } elseif ($predicate->predicateOperatorType == PredicateOperatorType::between) {
            $this->prepareBetween($predicate, $clause);
        }
    }

    private function buildConstantValueExpression(Expression $expression, ArrayClass &$arguments, string $prefix = "", string $suffix = ""): mixed
    {
        $constantValue = $expression->constantValue;
        if (is_string($constantValue)) {
            $constantValue = addcslashes($constantValue, "%_");
        } elseif (is_bool($constantValue)) {
            $constantValue = (int)$constantValue;
        } elseif ($constantValue instanceof ManagedObject) {
            $constantValue = $constantValue->objectID;
        }
        $argument = $constantValue;
        if (is_string($argument) || $prefix || $suffix) {
            $argument = "$prefix$argument$suffix";
            if ($prefix || $suffix || str_contains($argument, "\%") || str_contains($argument, "\_")) {
                $constantValue = "?";
            }
        }
        $arguments[] = $argument;
        return $constantValue;
    }

    private function buildComparisonExpression(Expression $expression, ArrayClass &$arguments, string $prefix = "", string $suffix = ""): mixed
    {
        if ($expression->expressionType === ExpressionType::constantValue) {
            return $this->buildConstantValueExpression($expression, $arguments, $prefix, $suffix);
        }
        return $this->buildExpression($expression);
    }

    private function prepareClauseWithSimplePredicate(ComparisonPredicate $predicate, string &$clause, string $operator, string $prefix = "", string $suffix = ""): void
    {
        /** @var ArrayClass<mixed> $arguments */
        $arguments = new ArrayClass();
        $left = $this->buildComparisonExpression($predicate->leftExpression, $arguments, $prefix, $suffix);
        $right = $this->buildComparisonExpression($predicate->rightExpression, $arguments, $prefix, $suffix);
        $numberOfArguments = $arguments->count;
        if ($numberOfArguments === 1) {
            $key = $arguments->first;
            if (is_string($key)) {
                $key = str_replace([$suffix, $prefix], "", $key);
            }
            if (is_equal($key, $right)) {
                $clause .= "$left $operator ?";
            } elseif (is_equal($key, $left)) {
                $clause .= "? $operator $right";
            } else {
                $clause .= "$left $operator $right";
            }
        } elseif ($numberOfArguments === 2) {
            $clause .= "? $operator ?";
        } else {
            $clause .= "$left $operator $right";
        }
        $this->arguments->appendContentsOf($arguments);
    }

    private function prepareIn(ComparisonPredicate $predicate, string &$clause): void
    {
        $leftExpression = $predicate->leftExpression;
        $rightExpression = $predicate->rightExpression;
        $right = $rightExpression->constantValue ?? $rightExpression->collection;
        assert($right instanceof Sequence && !$right->isEmpty, sprintf("invalid argument: the right expression of an IN operator must be an non-empty \"%s\", (%s)%s given", Sequence::class, typeof($right), human_readable_value($right)));
        $clause .= "{$this->buildExpression($leftExpression)} IN (" . ArrayClass::repeating("?", $right->count)->join(", ") . ")";
        $this->arguments->appendContentsOf($right->map(fn(mixed $element): mixed => $element instanceof Expression ? $element->constantValue : $element));
    }

    private function prepareBetween(ComparisonPredicate $predicate, string &$clause): void
    {
        $leftExpression = $predicate->leftExpression;
        $rightExpression = $predicate->rightExpression;
        $right = $rightExpression->constantValue ?? $rightExpression->collection;
        assert($right instanceof ArrayClass && $right->count === 2, sprintf("invalid argument: the right expression of a BETWEEN operator must be a \"%s\" with exactly two elements, (%s)%s given", ArrayClass::class, typeof($right), human_readable_value($right)));
        $clause .= "({$this->buildExpression($leftExpression)} BETWEEN ? AND ?)";
        $this->arguments->appendContentsOf($right->map(fn(mixed $element): mixed => $element instanceof Expression ? $element->constantValue : $element));
    }

    private function prepareEqual(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "=";
        if ($this->isNullExpression($predicate->leftExpression) || $this->isNullExpression($predicate->rightExpression)) {
            $operator = "<=>";
        }
        $this->prepareClauseWithSimplePredicate($predicate, $clause, $operator);
    }

    private function prepareNotEqual(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "!=";
        if ($this->isNullExpression($predicate->rightExpression)) {
            $operator = "IS NOT";
        } elseif ($this->isNullExpression($predicate->leftExpression) || $this->isNullExpression($predicate->rightExpression)) {
            $operator = "<>";
        }
        $this->prepareClauseWithSimplePredicate($predicate, $clause, $operator);
    }

    private function prepareLike(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "LIKE";
        if (!($predicate->options & ComparisonPredicateOptions::caseInsensitive)) {
            $operator .= " BINARY";
        }
        $this->prepareClauseWithSimplePredicate($predicate, $clause, $operator);
    }

    private function prepareMatches(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "REGEXP";
        if (!($predicate->options & ComparisonPredicateOptions::caseInsensitive)) {
            $operator .= " BINARY";
        }
        $this->prepareClauseWithSimplePredicate($predicate, $clause, $operator);
    }

    private function prepareBeginsWith(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "LIKE";
        if (!($predicate->options & ComparisonPredicateOptions::caseInsensitive)) {
            $operator .= " BINARY";
        }
        $this->prepareClauseWithSimplePredicate($predicate, $clause, $operator, "", "%");
    }

    private function prepareEndsWith(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "LIKE";
        if (!($predicate->options & ComparisonPredicateOptions::caseInsensitive)) {
            $operator .= " BINARY";
        }
        $this->prepareClauseWithSimplePredicate($predicate, $clause, $operator, "%");
    }

    private function prepareContains(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "LIKE";
        if (!($predicate->options & ComparisonPredicateOptions::caseInsensitive)) {
            $operator .= " BINARY";
        }
        $this->prepareClauseWithSimplePredicate($predicate, $clause, $operator, "%", "%");
    }

    private function preparePredicate(Predicate $predicate, string &$clause): void
    {
        if ($predicate instanceof CompoundPredicate) {
            $this->prepareCompoundPredicate($predicate, $clause);
        } elseif ($predicate instanceof ComparisonPredicate) {
            $this->prepareComparisonPredicate($predicate, $clause);
        }
    }

    private function prepareCompoundPredicate(CompoundPredicate $predicate, string &$clause): void
    {
        $subpredicates = $predicate->subpredicates;
        $max = $subpredicates->indexBefore($subpredicates->endIndex);
        $type = $predicate->compoundPredicateType;
        if ($type === CompoundPredicateLogicalType::not) {
            $clause .= "NOT ";
        }
        if ($max) {
            $clause .= "(";
        }
        foreach ($subpredicates as $idx => $subpredicate) {
            $this->preparePredicate($subpredicate, $clause);
            if ($idx < $max) {
                if ($type === CompoundPredicateLogicalType::and) {
                    $clause .= " AND ";
                } elseif ($type === CompoundPredicateLogicalType::or) {
                    $clause .= " OR ";
                }
            }
        }
        if ($max) {
            $clause .= ")";
        }
    }

    private function prepareComparisonPredicate(ComparisonPredicate $predicate, string &$clause): void
    {
        if ($predicate->comparisonPredicateModifier === ComparisonPredicateModifier::direct) {
            $this->buildClauseWithSimplePredicate($predicate, $clause);
        } elseif ($this->isKeyPathExpression($predicate->leftExpression) || $this->isKeyPathExpression($predicate->rightExpression)) {
            $this->buildClauseWithSelectPredicate($predicate, $clause);
        } else {
            fatal_error("Invalid argument: invalid predicate $predicate");
        }
    }

    private function buildClauseWithSelectPredicate(ComparisonPredicate $predicate, string &$clause): void
    {
        $expressions = new ArrayClass([$predicate->leftExpression, $predicate->rightExpression]);
        if (!($expression = $expressions->first(fn(Expression $expression): bool => $expression->expressionType === ExpressionType::keyPath))) {
            fatal_error();
        }
        $keyPath = $this->buildKeyPathExpression($expression);
        [$entityAlias, $columnName] = explode(".", $keyPath);
        $relationship = (function () use ($expression): ?SQLRelationship {
            $relationship = null;
            $entity = $this->entity;
            $keys = new Set(explode(".", $expression->keyPath));
            foreach ($keys as $key) {
                $property = $entity->propertiesByName[$key];
                if ($property instanceof SQLRelationship) {
                    $entity = $property->destinationEntity;
                    $relationship = $property;
                }
            }
            return $relationship;
        })() ?? fatal_error();
        $destinationEntity = $relationship->destinationEntity;
        $clause .= "$keyPath = ";
        $clause .= match ($predicate->comparisonPredicateModifier) {
            ComparisonPredicateModifier::direct => "",
            ComparisonPredicateModifier::all => "ALL ",
            ComparisonPredicateModifier::any => "ANY ",
        };
        $clause .= "(";
        $clause .= "SELECT $columnName FROM $destinationEntity->tableName AS $entityAlias WHERE ";
        $this->buildClauseWithSimplePredicate($predicate, $clause);
        $clause .= ")";
    }

    private function buildDerivedKeyPathExpression(Expression $expression, ?string $destination = null, ?bool &$isDeterministic = true): string
    {
        if (!$expression->usesKVC) {
            return $this->buildKeyPathExpression($expression, $isDeterministic);
        }
        $entity = $this->entity;
        $destination ??= $entity->tableName;
        [$keyPathToCollection, $collectionOperator, $keyPathToProperty] = kvc_components((string)$expression);
        if (!$keyPathToCollection || !$collectionOperator) {
            fatal_error("Invalid argument: unsupported expression \"$expression\"");
        }
        $relationship = $entity->propertiesByName[$keyPathToCollection] ?? fatal_error("Invalid argument: invalid key path \"$keyPathToCollection\" for entity $entity->debugDescription");
        $relationship instanceof SQLToMany || $relationship instanceof SQLManyToMany ?: fatal_error("Invalid argument: unsupported expression \"$expression\"");
        $inverseRelationship = $relationship->inverseRelationship;
        $destinationEntity = $relationship->destinationEntity;
        if (($collectionOperator === KeyValueOperator::countKeyValueOperator && $keyPathToProperty) || ($collectionOperator !== KeyValueOperator::countKeyValueOperator && !$keyPathToProperty)) {
            fatal_error("Invalid expression \"$expression\"");
        }
        /** @var ArrayClass<string|PropertyDescription> $propertiesToFetch */
        $propertiesToFetch = new ArrayClass([$inverseRelationship->relationshipDescription]);
        if ($collectionOperator !== KeyValueOperator::countKeyValueOperator) {
            $propertiesToFetch->append($keyPathToProperty);
        }
        /** @var SQLFetchRequestContext $requestContext */
        $requestContext = $this->requestContext;
        $managedObjectModel = $requestContext->sqlCore->persistentStoreCoordinator->managedObjectModel;
        /** @var EntityDescription $entityForFetchRequest */
        $entityForFetchRequest = $managedObjectModel->entitiesByName[$destinationEntity->entityDescription->name];
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $entityForFetchRequest;
        $fetchRequest->propertiesToFetch = $propertiesToFetch;
        $fetchRequest->resultType = FetchRequestResultType::countResultType;
        $generator = new SQLGenerator(new SQLFetchRequestContext($fetchRequest, $requestContext->context, $requestContext->sqlCore));
        $generator->autoDistinct = false;
        $generator->raisesForNotApplicableKeys = false;
        $generator->keyValueOperator = $collectionOperator;
        $generator->resetSQL();
        $string = "($generator->statement";
        $string .= $generator->whereClause ? " AND " : " WHERE ";
        if ($relationship instanceof SQLToMany) {
            if ($destinationEntity->isKindOfSQLEntity($entity)) {
                //FIXME: It doesn't work, the right side of the predicate apparently requires a constant value
                $string .= "{$destinationEntity->tableName}_{$relationship->inverseToOne->name}.{$entity->primaryKey->columnName} = $destination.{$relationship->inverseToOne->foreignKey->columnName}";
            } else {
                $string .= "$destinationEntity->tableName.{$relationship->inverseToOne->foreignKey->columnName} = $destination.{$entity->primaryKey->columnName}";
            }
        } else {
            $string .= "{$destinationEntity->tableName}_$relationship->correlationTableName.$relationship->inverseColumnName = $destination.{$entity->primaryKey->columnName}";
        }
        return "$string)";
    }

    public function buildDerivationExpression(Expression $expression, ?string $destination = null, ?bool &$isDeterministic = true): string
    {
        return match ($expression->expressionType) {
            ExpressionType::conditional => $this->buildConditionalExpression($expression, $isDeterministic),
            ExpressionType::function => $this->buildFunctionExpression($expression, $isDeterministic),
            ExpressionType::keyPath => $this->buildDerivedKeyPathExpression($expression, $destination, $isDeterministic),
            default => fatal_error("Invalid argument: unsupported expression \"$expression\"")
        };
    }

    private function buildFunctionExpression(Expression $expression, ?bool &$isDeterministic = true): string
    {
        $operator = $expression->operand;
        if (!$operator instanceof ExpressionOperator) {
            fatal_error("Invalid argument: unsupported expression \"$expression\"");
        }
        $isDeterministic = $operator->isDeterministic;
        $arguments = $expression->arguments ?? fatal_error("Invalid argument: unsupported expression \"$expression\"");
        switch ($operator->operatorType) {
            case ExpressionOperatorType::addTo:
            case ExpressionOperatorType::fromSubtract:
            case ExpressionOperatorType::multiplyBy:
            case ExpressionOperatorType::divideBy:
            case ExpressionOperatorType::modulusBy:
            case ExpressionOperatorType::bitwiseAndWith:
            case ExpressionOperatorType::bitwiseOrWith:
            case ExpressionOperatorType::bitwiseXorWith:
            case ExpressionOperatorType::leftshiftBy:
            case ExpressionOperatorType::rightshiftBy:
                return "({$arguments->map(fn(Expression $argument): string => $this->buildExpression($argument))->join(" $operator->operatorSymbol ")})";
            case ExpressionOperatorType::sum:
            case ExpressionOperatorType::count:
            case ExpressionOperatorType::min:
            case ExpressionOperatorType::max:
            case ExpressionOperatorType::stddev:
            case ExpressionOperatorType::sqrt:
            case ExpressionOperatorType::ln:
            case ExpressionOperatorType::log:
            case ExpressionOperatorType::exp:
            case ExpressionOperatorType::ceiling:
            case ExpressionOperatorType::abs:
            case ExpressionOperatorType::floor:
            case ExpressionOperatorType::cast:
            case ExpressionOperatorType::now:
            case ExpressionOperatorType::year:
            case ExpressionOperatorType::month:
            case ExpressionOperatorType::week:
            case ExpressionOperatorType::day:
            case ExpressionOperatorType::hour:
            case ExpressionOperatorType::minute:
            case ExpressionOperatorType::second:
            case ExpressionOperatorType::date:
            case ExpressionOperatorType::uuid:
            case ExpressionOperatorType::substring:
            case ExpressionOperatorType::replace:
            case ExpressionOperatorType::length:
            case ExpressionOperatorType::isNull:
            case ExpressionOperatorType::ifNull:
            case ExpressionOperatorType::nullIf:
                $function = strtoupper($operator->operatorSymbol);
                break;
            case ExpressionOperatorType::average:
                $function = "AVG";
                break;
            case ExpressionOperatorType::raiseToPower:
                $function = "POW";
                break;
            case ExpressionOperatorType::random:
                $function = "RAND";
                break;
            case ExpressionOperatorType::trunc:
                $function = "TRUNCATE";
                break;
            case ExpressionOperatorType::uppercase:
                $function = "UPPER";
                break;
            case ExpressionOperatorType::lowercase:
                $function = "LOWER";
                break;
            case ExpressionOperatorType::concat:
                $function = "CONCAT_WS";
                break;
            case ExpressionOperatorType::index:
                $function = "ELT";
                break;
            case ExpressionOperatorType::currentDate:
                $function = "CURDATE";
                break;
            case ExpressionOperatorType::dateFormat:
                $function = "DATE_FORMAT";
                break;
            case ExpressionOperatorType::dateDiff:
                $function = "TIMESTAMPDIFF";
                break;
            default:
                $function = "";
                break;
        }
        $function ?: fatal_error("Invalid argument: unsupported expression \"$expression\"");
        return "$function({$arguments->map(fn(Expression $argument): string => $this->buildExpression($argument))->join(match ($operator->operatorType) {
                ExpressionOperatorType::cast => " AS ",
                default => ", ",
            })})";
    }

    private function buildConditionalExpression(Expression $expression, ?bool &$isDeterministic = true): string
    {
        $predicate = "";
        $this->preparePredicate($expression->predicate, $predicate);
        $true = $expression->true;
        if ($true->expressionType === ExpressionType::keyPath) {
            $true = $this->buildKeyPathExpression($true, $isDeterministic);
        } elseif ($true->expressionType === ExpressionType::function) {
            $true = $this->buildFunctionExpression($true, $isDeterministic);
        }
        $false = $expression->false;
        if ($false->expressionType === ExpressionType::keyPath) {
            $false = $this->buildKeyPathExpression($false, $isDeterministic);
        } elseif ($false->expressionType === ExpressionType::function) {
            $false = $this->buildFunctionExpression($false, $isDeterministic);
        }
        $isDeterministic = false;
        return "IF($predicate, $true, $false)";
    }

    private function buildConstantExpression(/** @noinspection PhpUnusedParameterInspection */ Expression $expression, ?bool &$isDeterministic = true): string
    {
        $value = $expression->constantValue;
        if ($value instanceof ArrayClass) {
            return $value->join(", ");
        }
        if ($value instanceof Date) {
            return "'$value'";
        }
        return match ($value) {
            "MICROSECOND", "SECOND", "MINUTE", "HOUR", "DAY", "WEEK", "MONTH", "QUARTER", "YEAR" => $value,
            default => $expression->description
        };
    }

    private function buildExpression(Expression $expression, ?bool &$isDeterministic = true): string
    {
        return match ($expression->expressionType) {
            ExpressionType::constantValue => $this->buildConstantExpression($expression, $isDeterministic),
            ExpressionType::keyPath => $this->buildKeyPathExpression($expression, $isDeterministic),
            ExpressionType::function => $this->buildFunctionExpression($expression, $isDeterministic),
            ExpressionType::conditional => $this->buildConditionalExpression($expression, $isDeterministic),
            ExpressionType::aggregate => $this->buildAggregateExpression($expression, $isDeterministic),
            default => $expression->description,
        };
    }

    private function buildAggregateExpression(Expression $expression, ?bool &$isDeterministic = true): string
    {
        return $expression->collection->map(fn(Expression $argument): string => $this->buildExpression($argument, $isDeterministic))->join(", ");
    }

    private function buildGroupByClause(ArrayClass $propertiesToGroupBy): void
    {
        $this->appendGroupByClauseToSQL();
        $this->groupByClause .= $propertiesToGroupBy->map(fn(PropertyDescription $property): string => "{$this->entity->tableName}.$property->name")->join(", ");
    }

    private function buildOrderByClause(ArrayClass $descriptors): void
    {
        $raisesForNotApplicableKeys = $this->raisesForNotApplicableKeys;
        $this->raisesForNotApplicableKeys = false;
        /** @psalm-suppress RedundantCondition */
        if (SS_COREDATA_USES_RELATIONSHIPS_SORT_DESCRIPTORS):
            /** @var Set<SQLToMany> $toManyRelationships */
            $toManyRelationships = $this->keyPathExpressionsForFetchRequestSerialization()->union($this->keyPathExpressionsForFetchRequestPredicate())->flatMap(fn(Expression $expression): ArrayClass => $this->propertiesFromKeyPathExpression($expression, fn(SQLProperty $property): bool => $property instanceof SQLForeignKey || $property instanceof SQLToMany)->compactMap(function (SQLProperty $property): ?SQLProperty {
                $toMany = null;
                if ($property instanceof SQLForeignKey) {
                    $toMany = $property->toOneRelationship->inverseRelationship;
                } elseif ($property instanceof SQLToMany) {
                    $toMany = $property;
                }
                if ($toMany instanceof SQLToMany && $toMany->isOrdered) {
                    $property = $toMany->inverseToOne->foreignOrderKey->entity->propertiesByName[$toMany->inverseToOne->foreignOrderKey->columnName];
                    if ($property instanceof SQLProperty && !$property->isTransient) {
                        return $toMany;
                    }
                }
                return null;
            }));
            $descriptors->appendContentsOf($toManyRelationships->map(fn(SQLToMany $toMany) => new SortDescriptor($toMany->inverseToOne->foreignOrderKey->columnName)));
        endif;
        if (!$descriptors->isEmpty) {
            $clauses = new Set($descriptors->map(fn(SortDescriptor $descriptor): string => sprintf("%s %s", $this->buildKeyPathExpression(Expression::expressionForKeyPath($descriptor->key)), $descriptor->ascending ? "ASC" : "DESC"))->filter(fn(string $string): bool => str_contains($string, ".")));
            if (!$clauses->isEmpty) {
                $this->appendOrderByClauseToSQL();
                $this->orderByClause .= $clauses->join(", ");
            }
        }
        $this->raisesForNotApplicableKeys = $raisesForNotApplicableKeys;
    }

    private function coercedValue(ManagedObject|Dictionary $object, AttributeDescription $attribute): mixed
    {
        $value = $object->valueForKey($attribute->name);
        ManagedObject::coerceValue($value, $attribute, true);
        return $value;
    }

    /**
     * @param SQLEntity $entity
     * @param ArrayClass<ManagedObject> $insertedObjects
     */
    private function prepareInsertStatement(SQLEntity $entity, ArrayClass $insertedObjects): void
    {
        /** @var ArrayClass<mixed> $arguments */
        $arguments = new ArrayClass();
        /** @var Set<string> $columnNames */
        $columnNames = new Set();
        $columnNames->appendContentsOf([$entity->primaryKey->columnName, $entity->entityKey->columnName]);
        foreach ($insertedObjects as $insertedObject) {
            foreach ($entity->properties as $property) {
                if ($property instanceof SQLPrimaryKey || $property instanceof SQLEntityKey) {
                    $columnNames->append($property->name);
                } elseif ($property instanceof SQLAttribute) {
                    if ($insertedObject->changedValuesForCurrentEvent()->offsetExists($property->name)) {
                        $columnNames->append($property->columnName);
                    }
                } elseif ($property instanceof SQLToOne) {
                    $columnNames->append($property->foreignKey->columnName);
                } elseif ($property instanceof SQLToMany) {
                    if ($insertedObject->entity->propertiesByName[$property->name]) {
                        /** @var Set<ManagedObject> $mutableSet */
                        $mutableSet = $insertedObject->mutableSetValueForKey($property->name);
                        foreach ($mutableSet as $managedObject) {
                            $managedObject->setPrimitiveValueForKey($insertedObject->objectID, $property->inverseToOne->foreignKey->columnName);
                        }
                    }
                }
            }
        }
        foreach ($insertedObjects as $insertedObject) {
            foreach ($columnNames as $columnName) {
                $property = $entity->propertiesByName[$columnName];
                if ($property instanceof SQLPrimaryKey) {
                    $arguments->append($insertedObject->objectID->referenceObject);
                } elseif ($property instanceof SQLEntityKey) {
                    $arguments->append($insertedObject->entity->name);
                } elseif ($property instanceof SQLAttribute) {
                    $arguments->append($this->coercedValue($insertedObject, $property->attributeDescription));
                } elseif ($property instanceof SQLForeignKey) {
                    $value = $insertedObject->primitiveValueForKey($property->name);
                    if ($value instanceof ManagedObject) {
                        $value = $value->objectID;
                    }
                    if ($value instanceof ManagedObjectID) {
                        assert(!$value->isTemporaryID, "Invalid argument: \"$property->columnName\" cannot be a temporary ID");
                        $value = $value->referenceObject;
                    }
                    $arguments->append($value);
                }
            }
        }
        $this->string = "INSERT INTO `$entity->tableName` (" . $columnNames->map(fn(string $columnName): string => "`$columnName`")->join(", ") . ") VALUES " . ArrayClass::repeating("(" . ArrayClass::repeating("?", $columnNames->count)->join(", ") . ")", $insertedObjects->count)->join(", ") . " ON DUPLICATE KEY UPDATE {$columnNames->map(fn(string $columnName): string => "`$columnName` = VALUES(`$columnName`)")->join(", ")}";
        $this->arguments = $arguments;
    }

    /**
     * @param SQLEntity $entity
     * @param ArrayClass<ManagedObject> $updatedObjects
     */
    private function prepareUpdateStatement(SQLEntity $entity, ArrayClass $updatedObjects): void
    {
        /** @var ArrayClass<mixed> $arguments */
        $arguments = new ArrayClass();
        /** @var Set<string> $columnNames */
        $columnNames = new Set();
        foreach ($updatedObjects as $updatedObject) {
            foreach ($entity->properties as $property) {
                if ($property instanceof SQLAttribute || $property instanceof SQLToOne) {
                    $key = $property->name;
                    $exist = $updatedObject->changedValuesForCurrentEvent()->offsetExists($key);
                    if ($property instanceof SQLToOne) {
                        $exist = $updatedObject->changedValues()->offsetExists($key);
                    }
                    if ($exist) {
                        if ($property instanceof SQLToOne) {
                            $key = $property->foreignKey->columnName;
                        }
                        $columnNames->append($key);
                    }
                }
            }
        }
        $this->string = "UPDATE `$entity->tableName` SET {$columnNames->map(fn(string $columnName): string => "`$columnName` = (CASE {$updatedObjects->map(function(ManagedObject $object) use ($entity, $columnName, &$arguments): string {
            /** @var SQLAttribute|SQLForeignKey $property */
            $property = $entity->propertiesByName[$columnName];
            if ($property instanceof SQLAttribute) {
                $value = $this->coercedValue($object, $property->attributeDescription);
            } else {
                $value = $object->valueForKeyPath("$property->name.{$entity->primaryKey->name}");
            }
            $arguments->appendContentsOf([$object->objectID->referenceObject, $value]);
            return "WHEN `{$entity->primaryKey->columnName}` = ? THEN ?";
        })->join(" ")} ELSE `$columnName` END)")->join(", ")} WHERE `{$entity->primaryKey->columnName}` IN (" . ArrayClass::repeating("?", $updatedObjects->count)->join(",") . ")";
        $arguments->appendContentsOf($updatedObjects->map(fn(ManagedObject $object): string|int => $object->objectID->referenceObject));
        $this->arguments = $arguments;
    }

    private function prepareDeleteStatement(SQLEntity $entity, ArrayClass $objects): void
    {
        $this->string = "DELETE FROM `$entity->tableName` WHERE `{$entity->primaryKey->columnName}` IN (" . ArrayClass::repeating("?", $objects->count)->join(",") . ")";
        $this->arguments = $objects;
    }

    private function prepareStatementForBatchInsertRequest(): void
    {
        /** @var SQLBatchInsertRequestContext $requestContext */
        $requestContext = $this->requestContext;
        $request = $requestContext->request;
        $entity = $requestContext->sqlEntityForInsertRequest;
        /** @var ArrayClass<ManagedObject|Dictionary> $objectsToInsert */
        $objectsToInsert = new ArrayClass();
        if ($insertObjects = $request->objectsToInsert) {
            $objectsToInsert = $insertObjects;
        } elseif ($dictionaryHandler = $request->dictionaryHandler) {
            while (true) {
                $keyedValues = new Dictionary();
                $ok = $dictionaryHandler($keyedValues);
                $managedObject = EntityDescription::insertNewObject($entity->tableName, $requestContext->context);
                $managedObject->setValuesForKeys($keyedValues);
                if (!$ok) {
                    break;
                }
                $objectsToInsert[] = $managedObject;
            }
        } elseif ($managedObjectHandler = $request->managedObjectHandler) {
            /** @var ArrayClass<ManagedObject> $objectsToInsert */
            $objectsToInsert = new ArrayClass();
            while (true) {
                $managedObject = EntityDescription::insertNewObject($entity->tableName, $requestContext->context);
                if (!$managedObjectHandler($managedObject)) {
                    break;
                }
                $objectsToInsert[] = $managedObject;
            }
        }
        /** @var ArrayClass<string> $columnNames */
        $columnNames = new ArrayClass();
        $columnNames->appendContentsOf([$entity->entityKey->columnName]);
        $firstObjectToInsert = $objectsToInsert->first ?? fatal_error();
        if ($firstObjectToInsert instanceof ManagedObject) {
            $columnNames->appendContentsOf($firstObjectToInsert->changedValuesForCurrentEvent()->keys);
        }
        $columns = $entity->columnsToCreate->filter(fn(SQLColumn $column): bool => $columnNames->containsElement($column->columnName));
        $this->string = "INSERT INTO `$entity->tableName` ({$columns->map(fn(SQLColumn $column): string => "`$column->columnName`")->join(", ")}) VALUES " . ArrayClass::repeating("(" . ArrayClass::repeating("?", $columns->count)->join(", ") . ")", $objectsToInsert->count)->join(", ") . " RETURNING `{$entity->primaryKey->columnName}`";
        $this->arguments = $objectsToInsert->flatMap(fn(ManagedObject|Dictionary $object): ArrayClass => $columns->map(function (SQLColumn $column) use ($entity, $object): mixed {
            if ($column instanceof SQLEntityKey) {
                return $entity->tableName;
            }
            if ($column instanceof SQLAttribute) {
                $value = $object->valueForKey($column->name);
                ManagedObject::coerceValue($value, $column->attributeDescription, true);
                return $value;
            }
            return $object->valueForKey($column->name);
        }));
    }

    private function prepareStatementForBatchUpdateRequest(): void
    {
        $this->string = "UPDATE `{$this->entity->tableName}`";
    }

    private function appendSetStatementForBatchUpdateRequest(BatchUpdateRequest $request): void
    {
        /** @var ArrayClass<mixed> $arguments */
        $arguments = new ArrayClass();
        /** @var Dictionary $propertiesToUpdate */
        $propertiesToUpdate = $request->propertiesToUpdate;
        $this->string .= " SET {$propertiesToUpdate->map(function (mixed $value, string $key) use (&$arguments): string {
                if (is_string($value)) {
                    if ($this->entity->attributes->contains(fn(SQLAttribute $attribute): bool => $value === $attribute->name)) {
                        return "`$key` = $value";
                    }
                    $value = match($value) {
                        "UUID()", "CURRENT_TIMESTAMP" => $value,
                        default => $this->buildExpression(Expression::expressionWithFormat($value) ?? fatal_error("Unable to create expression \"$value\"")),
                    };
                    return "{$this->entity->tableName}.$key = $value";
                }
                $arguments[] = $value;
                return "{$this->entity->tableName}.$key = ?";
            })->join(", ")}";
        $this->arguments = $arguments;
    }

    private function prepareStatementForBatchDeleteRequest(BatchDeleteRequest $request): void
    {
        /** @var EntityDescription $entity */
        $entity = $request->fetchRequest->entity->isRootEntity ? $request->fetchRequest->entity : $request->fetchRequest->entity->rootEntity;
        /** @noinspection SqlWithoutWhere */
        $this->string = "DELETE `$entity->name` FROM `$entity->name`";
    }

    /**
     * @param Set $objects
     * @return Dictionary<ArrayClass>
     */
    private function groupedObjects(Set $objects): Dictionary
    {
        /** @var Dictionary<ArrayClass> $map */
        $map = new Dictionary();
        $first = $objects->first;
        if ($first instanceof PersistentHistoryTransaction) {
            $map["PersistentHistoryTransaction"] = new ArrayClass($objects);
        } elseif ($first instanceof PersistentHistoryChange) {
            $map["PersistentHistoryChange"] = new ArrayClass($objects);
        } elseif ($first instanceof ManagedObject) {
            $objects = $objects->sort(fn(ManagedObject $e0, ManagedObject $e1): int => $e0->entity->relationshipsByName->compactMap(fn(RelationshipDescription $relationship): ?EntityDescription => $e0->hasFaultForRelationshipNamed($relationship->name) ? $relationship->destinationEntity : null)->containsElement($e1->entity) ? ComparisonResult::orderedDescending->value : ComparisonResult::orderedAscending->value);
            foreach ($objects as $object) {
                /** @var ArrayClass $value */
                $value = $map[$object->entity->name] ?? new ArrayClass();
                if (!$value->containsElement($object)) {
                    $value->append($object);
                }
                $map[$object->entity->name] = $value;
            }
        }
        return $map;
    }
}
