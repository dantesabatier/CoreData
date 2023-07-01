<?php

/** @noinspection PhpInternalEntityUsedInspection */

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 22:13
 */

namespace Sabatier\CoreData;

use InvalidArgumentException;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\CompareOptions;
use Sabatier\Foundation\ComparisonResult;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyValueOperator;
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
use Sabatier\Foundation\Set;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\UnknownKeyException;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\is_equal;
use function Sabatier\Foundation\kvc_components;
use function Sabatier\Foundation\string_contains;
use function Sabatier\Foundation\typeof;

/** @internal */
class SQLGenerator extends ObjectClass
{
    private string $string = "";
    private string $selectList = "";
    private string $joinClause = "";
    private string $whereClause = "";
    private string $groupByClause = "";
    private string $havingClause = "";
    private string $orderByClause = "";
    private FetchRequest $request;
    private SQLEntity $entity;
    /** @var ArrayClass */
    public ArrayClass $arguments;
    private bool $useDistinct = false;
    private string $keyValueOperator = KeyValueOperator::countKeyValueOperator;
    public bool $autoDistinct = true;
    public bool $raisesForNotApplicableKeys = true;

    public function __construct(public readonly SQLStoreRequestContext $requestContext)
    {
        unset($this->request);
        unset($this->entity);
        unset($this->arguments);
    }

    public function __get(string $name)
    {
        $requestContext = $this->requestContext;
        if ($name == "request") {
            if ($requestContext instanceof SQLBatchUpdateRequestContext || $requestContext instanceof SQLBatchDeleteRequestContext) {
                $this->$name = $requestContext->fetchContext->request;
            } elseif ($requestContext instanceof SQLFetchRequestContext) {
                $this->$name = $requestContext->request;
            }
            return $this->$name;
        } elseif ($name == "entity") {
            if ($requestContext instanceof SQLBatchUpdateRequestContext || $requestContext instanceof SQLBatchDeleteRequestContext) {
                $this->$name = $requestContext->fetchContext->sqlEntityForFetchRequest;
            } elseif ($requestContext instanceof SQLFetchRequestContext) {
                $this->$name = $requestContext->sqlEntityForFetchRequest;
            }
            return $this->$name;
        } elseif ($name == "arguments") {
            $this->$name = new ArrayClass();
            return $this->$name;
        } else {
            return $this->valueForUndefinedKey($name);
        }
    }

    public function statement(): ?SQLStatement
    {
        if ($this->requestContext instanceof SQLBatchUpdateRequestContext || $this->requestContext instanceof SQLBatchDeleteRequestContext || $this->requestContext instanceof SQLFetchRequestContext) {
            return $this->newSQLStatementForPersistentStoreRequest();
        } elseif ($this->requestContext instanceof SQLSaveChangesRequestContext) {
            return $this->newSQLStatementForSaveChangesRequestContext();
        } else {
            return null;
        }
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
                if (!$value->isEmpty() && ($entity = $model->entity($key))) {
                    $statements->append($this->newSQLStatementForSaveInsertChanges($entity, $value));
                }
            }
        }
        if ($updatedObjects = $requestContext->request->updatedObjects) {
            $dictionary = $this->groupedObjects($updatedObjects);
            foreach ($dictionary as $key => $value) {
                if (!$value->isEmpty() && ($entity = $model->entity($key))) {
                    $statements->append($this->newSQLStatementForSaveUpdateChanges($entity, $value));
                }
            }
        }
        if ($deletedObjects = $requestContext->request->deletedObjects) {
            $dictionary = $this->groupedObjects($deletedObjects);
            foreach ($dictionary as $key => $value) {
                if (!$value->isEmpty() && ($entity = $model->entity($key))) {
                    $statements->append($this->newSQLStatementForSaveDeleteChanges($entity, $entity->entityDescription->isPersistentHistoryEntity ? $value->map(fn(PersistentHistoryTransaction $transaction): int => $transaction->transactionNumber) : $value->map(fn(ManagedObject $object): int|string => $object->objectID->referenceObject)));
                }
            }
        }
        if (!$statements->isEmpty()) {
            /** @psalm-suppress RedundantCondition, TypeDoesNotContainType */
            if (/** @phpstan-ignore-line */ SS_COREDATA_DISABLE_FOREIGN_KEY_CHECKS) :
                if ($statements->contains(fn(SQLStatement $statement): bool => str_starts_with($statement->string, "INSERT"))) {
                    $statements->insertAt(new SQLStatement("/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */"), 0);
                    $statements->append(new SQLStatement("/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */"));
                }
            endif;
            return SQLStatement::merging($statements);
        }
        return null;
    }

    private function startSQL(PersistentStoreRequest $request): void
    {
        if ($request instanceof FetchRequest) {
            /** @var ArrayClass<PropertyDescription> $propertiesToGroupBy */
            $propertiesToGroupBy = $request->propertiesToGroupBy?->compactMap(fn(PropertyDescription|string $property): ?PropertyDescription => $property instanceof PropertyDescription ? $property : $request->entity->propertiesByName[$property]) ?? new ArrayClass();
            if (!$propertiesToGroupBy->isEmpty() && $request->resultType !== FetchRequestResultType::dictionaryResultType) {
                throw new InvalidArgumentException(sprintf("Invalid fetch request: GROUP BY requires %s, %s given", human_readable_value(FetchRequestResultType::dictionaryResultType), human_readable_value($request->resultType)));
            }
            $this->useDistinct = $request->returnsDistinctResults;
            if (!$this->useDistinct && $this->autoDistinct) {
                /** @psalm-suppress all */
                $this->useDistinct = ($request->propertiesToFetch?->compactMap(fn(PropertyDescription|string $property): ?PropertyDescription => $property instanceof PropertyDescription ? $property : $request->entity->propertiesByName[$property])?->contains(fn(PropertyDescription $property): bool => $property instanceof RelationshipDescription)) || ($request->serialization->contains(fn(mixed $e): bool => $e instanceof Dictionary));
            }
            $this->resetSQL();
            $this->prepareSelectStatementWithFetchRequest($request);
            $this->prepareJoinStatementsForPredicateAndRelationships();
            $predicate = $request->predicate;
            /** @phpstan-ignore-next-line */
            if (!$request->includesSubentities || (!$request->entity->isPersistentHistoryEntity && !$request->entity->isRootEntity && ($request->entity->subentities->isEmpty() || !$request->entity->superentity?->isRootEntity || $request->entity->superentity?->subentities->count() > 1))) {
                $mandatory = new ComparisonPredicate(Expression::expressionForKeyPath($this->entity->entityKey->columnName), Expression::expressionForConstantValue($request->entity->name));
                if ($predicate) {
                    $predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([$predicate, $mandatory]));
                } else {
                    $predicate = $mandatory;
                }
            }
            if ($predicate) {
                $this->appendWhereClauseToSQL();
                $this->preparePredicate($predicate, $this->whereClause);
            }
            $this->appendFromClauseToSQL();
            $this->appendSQL($this->selectList);
            $this->appendSQL($this->joinClause);
            $this->appendSQL($this->whereClause);
            if (!$propertiesToGroupBy->isEmpty()) {
                $this->buildGroupByClause($propertiesToGroupBy);
                $this->appendSQL($this->groupByClause);
                if ($havingPredicate = $request->havingPredicate) {
                    $this->appendHavingClauseToSQL();
                    $this->preparePredicate($havingPredicate, $this->havingClause);
                }
                $this->appendSQL($this->havingClause);
            }
            if ($request->resultType !== FetchRequestResultType::countResultType) {
                $this->buildOrderByClause($request->sortDescriptors);
                $this->appendSQL($this->orderByClause);
            }
            if ($request->fetchLimit) {
                $this->appendLimitClauseToSQL($request->fetchLimit);
            }
            if ($request->fetchOffset) {
                $this->appendOffsetClauseToSQL($request->fetchOffset);
            }
            $this->endSQL();
        } elseif ($request instanceof BatchUpdateRequest) {
            $this->prepareStatementForBatchUpdateRequest();
            $this->prepareJoinStatementsForPredicateAndRelationships();
            $this->appendSQL($this->joinClause);
            $this->appendSetStatementForBatchUpdateRequest($request);
            if ($predicate = $request->predicate) {
                $this->appendWhereClauseToSQL();
                $this->preparePredicate($predicate, $this->whereClause);
                $this->appendSQL($this->whereClause);
            }
            $this->endSQL();
        } elseif ($request instanceof BatchDeleteRequest) {
            $this->prepareStatementForBatchDeleteRequest($request);
            $this->prepareJoinStatementsForPredicateAndRelationships();
            $this->appendSQL($this->joinClause);
            if ($predicate = $request->fetchRequest->predicate) {
                $this->appendWhereClauseToSQL();
                $this->preparePredicate($predicate, $this->whereClause);
                $this->appendSQL($this->whereClause);
            }
            $this->endSQL();
        }
    }

    private function endSQL(): void
    {
        $delimiter = ";";
        if (str_ends_with($this->string, $delimiter)) {
            $this->string = rtrim($this->string, ";");
        }
    }

    private function resetSQL(): void
    {
        $this->string = "";
        $this->selectList = "";
        $this->joinClause = "";
        $this->whereClause = "";
        $this->groupByClause = "";
        $this->havingClause = "";
        $this->orderByClause = "";
        $this->arguments->removeAll();
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
            $keys = $request->serialization->keys;
            /** @var ArrayClass<string|PropertyDescription> $properties */
            $properties = new ArrayClass();
            if ($propertiesToFetch = $request->propertiesToFetch) {
                $properties->appendContentsOf($propertiesToFetch->filter(fn(PropertyDescription|string $property): bool => $property instanceof PropertyDescription ? !$keys->containsElement($property->name) : !$keys->containsElement($property)));
            }
            $properties->appendContentsOf($keys->filter(fn(string $key): bool => $request->entity->attributesByName->contains(fn(AttributeDescription $attribute): bool => $attribute->name === $key)));
            /** @psalm-suppress InvalidArgument */
            $columnNames->appendContentsOf($properties->compactMap(fn(PropertyDescription|string $property): ?PropertyDescription => is_string($property) ? $request->entity->attributesByName[$property] : ($property instanceof AttributeDescription || $property instanceof ExpressionDescription ? $property : null))->map(function (PropertyDescription $property) use ($entity): string {
                if ($property instanceof AttributeDescription) {
                    if ($property instanceof DerivedAttributeDescription && str_contains((string)$property->derivationExpression, "@")) {
                        return "{$this->buildDerivedAttributeDescription($property)} AS $property->name";
                    }
                } elseif ($property instanceof ExpressionDescription) {
                    $expression = $property->expression ?? throw new InvalidArgumentException();
                    if ($expression->expressionType == ExpressionType::function) {
                        return "{$this->buildFunctionExpression($expression)} AS $property->name";
                    } elseif ($expression->expressionType == ExpressionType::conditional) {
                        return "{$this->buildConditionalExpression($expression)} AS $property->name";
                    }
                    throw new InvalidArgumentException("Invalid argument: unsupported expression $expression");
                }
                return "$entity->tableName.$property->name";
            }));
        }
        $this->selectList .= $columnNames->join(", ");
    }

    private function prepareSelectStatementWithFetchRequest(FetchRequest $request): void
    {
        $this->selectList = "SELECT ";
        switch ($request->resultType) {
            case FetchRequestResultType::managedObjectResultType:
            case FetchRequestResultType::managedObjectIDResultType:
            case FetchRequestResultType::dictionaryResultType:
                if ($this->useDistinct) {
                    $this->selectList .= "DISTINCT ";
                }
                $this->appendSelectListToSQLForRequest($request);
                break;
            case FetchRequestResultType::countResultType:
                $this->selectList .= strtoupper($this->keyValueOperator);
                $this->selectList .= "(";
                if ($this->useDistinct) {
                    $this->selectList .= "DISTINCT ";
                }
                $this->appendSelectListToSQLForRequest($request);
                $this->selectList .= ")";
                break;
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
        $this->joinClause = (new Set(explode(" LEFT JOIN ", $this->joinClause)))->join(" LEFT JOIN ");
        $this->raisesForNotApplicableKeys = $raisesForNotApplicableKeys;
    }

    private function appendJoinDestinationEntity(SQLEntity $destinationEntity, string $destinationPath): void
    {
        if (!$destinationEntity->isRootEntity) {
            $this->joinClause .= " AND ";
            if (!$this->request->includesSubentities || $destinationEntity->subentities->isEmpty()) {
                $this->joinClause .= "$destinationPath.{$destinationEntity->entityKey->columnName} = '$destinationEntity->tableName'";
            } else {
                $this->joinClause .= "({$destinationEntity->subentities->map(fn(SQLEntity $subentity): string => "$destinationPath.{$destinationEntity->entityKey->columnName} = '$subentity->tableName'")->join(" OR ")})";
            }
        }
    }

    private function addJoinForToOneRelationship(SQLToOne $toOne, string $sourcePath = "", string $destinationPath = ""): void
    {
        $sourceEntity = $toOne->entity;
        $inverseRelationship = $toOne->inverseRelationship;
        $destinationEntity = $toOne->destinationEntity;
        if ($inverseRelationship instanceof SQLToOne) {
            $columnName = $inverseRelationship->foreignKey->columnName;
        } else {
            $columnName = $destinationEntity->primaryKey->columnName;
        }
        if (empty($sourcePath)) {
            $sourcePath = $sourceEntity->tableName;
        }
        if (empty($destinationPath)) {
            $destinationPath = "{$sourceEntity->tableName}_$toOne->name";
        }
        $this->appendJoinClauseToSQL();
        $this->joinClause .= "`$destinationEntity->tableName` AS $destinationPath ON $destinationPath.$columnName = ";
        if ($inverseRelationship instanceof SQLToOne) {
            $this->joinClause .= "$sourcePath.{$sourceEntity->primaryKey->columnName}";
        } else {
            $this->joinClause .= "$sourcePath.{$toOne->foreignKey->columnName}";
        }
        if (!$sourceEntity->entityDescription->isPersistentHistoryEntity) {
            $this->appendJoinDestinationEntity($destinationEntity, $destinationPath);
        }
    }

    private function addJoinForToManyRelationship(SQLToMany $toMany, string $sourcePath = "", string $destinationPath = ""): void
    {
        $sourceEntity = $toMany->entity;
        $inverseToOne = $toMany->inverseToOne;
        $destinationEntity = $toMany->destinationEntity;
        if (!$destinationEntity->isRootEntity) {
            /** @var SQLEntity $destinationEntity */
            $destinationEntity = $destinationEntity->rootEntity;
        }
        if (empty($sourcePath)) {
            $sourcePath = $sourceEntity->tableName;
        }
        if (empty($destinationPath)) {
            $destinationPath = "{$sourceEntity->tableName}_$toMany->name";
        }
        $this->appendJoinClauseToSQL();
        $this->joinClause .= "`$destinationEntity->tableName` AS $destinationPath ON $destinationPath.{$inverseToOne->foreignKey->columnName} = ";
        $this->joinClause .= "$sourcePath.{$sourceEntity->primaryKey->columnName}";
        if (!$sourceEntity->entityDescription->isPersistentHistoryEntity) {
            $destinationEntity = $toMany->destinationEntity;
            $this->appendJoinDestinationEntity($destinationEntity, $destinationPath);
        }
    }

    private function addJoinForManyToManyRelationship(SQLManyToMany $manyToMany, string $sourcePath = "", string $destinationPath = ""): void
    {
        $correlationTableName = $manyToMany->correlationTableName;
        $inverseManyToMany = $manyToMany->inverseManyToMany;
        $sourceEntity = $inverseManyToMany->destinationEntity;
        if (!$sourcePath) {
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
        $this->appendJoinDestinationEntity($destinationEntity, $destinationPath);
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
        /** @var SQLEntity $entity */
        $entity = $this->entity;
        $cursor = $entity->tableName;
        /** @var FetchRequest $request */
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
                /** @var Dictionary|null $dictionary */
                $dictionary = $serialization[$name];
                if ($dictionary) {
                    $serialization = clone $dictionary;
                    $serializationKeys = $serialization->keys;
                    $serializationKeys->insertAt($entity->primaryKey->columnName, 0);
                    if (!$entity->entityDescription->isPersistentHistoryEntity) {
                        $serializationKeys->insertAt($entity->entityKey->columnName, 1);
                    }
                    /** @var ArrayClass<string> $columnNames */
                    $columnNames = $serializationKeys->compactMap(function (string $key) use ($entity, $destination): ?string {
                        $property = $entity->propertiesByName[$key];
                        if ($property instanceof SQLEntityKey || $property instanceof SQLPrimaryKey) {
                            return "$destination.$property->columnName AS {$destination}_$property->columnName";
                        } elseif ($property instanceof SQLAttribute) {
                            $attributeDescription = $property->attributeDescription;
                            if ($attributeDescription instanceof DerivedAttributeDescription && str_contains((string)$attributeDescription->derivationExpression, "@")) {
                                $propertyName = "{$destination}_$attributeDescription->name";
                                return (function () use ($entity, $propertyName, $attributeDescription): string {
                                    $bk = $this->entity;
                                    $this->entity = $entity;
                                    $result = $this->buildDerivedAttributeDescription($attributeDescription);
                                    $this->entity = $bk;
                                    return "$result AS $propertyName";
                                })();
                            }
                            return "$destination.$property->columnName AS {$destination}_$property->columnName";
                        }
                        return null;
                    });
                }
                if (!$columnNames->isEmpty()) {
                    $copy = clone $columnNames;
                    foreach ($copy as $columnName) {
                        if (str_contains($this->selectList, $columnName)) {
                            $columnNames->remove($columnName);
                            if (str_contains($columnName, "?")) {
                                $this->arguments->popFirst();
                            }
                        }
                    }
                    if (!$columnNames->isEmpty()) {
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
     * @param Dictionary|null $serialization
     * @param string|null $parent
     * @return Set<Expression>
     */
    private function keyPathExpressionsForFetchRequestSerialization(?Dictionary $serialization = null, ?string $parent = null): Set
    {
        /** @var Set<Expression> $expressions */
        $expressions = new Set();
        if ($parent !== null) {
            $parent = "$parent.";
        }
        /** @var FetchRequest $request */
        $request = $this->request;
        $serialization ??= $request->serialization;
        foreach ($serialization as $key => $value) {
            if ($value instanceof Dictionary) {
                /** @psalm-suppress PossiblyNullOperand */
                $current = $parent . $key;
                $expressions->append(Expression::expressionForKeyPath($current));
                $expressions->appendContentsOf($this->keyPathExpressionsForFetchRequestSerialization($value, $current));
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
            if ($predicate->leftExpression->expressionType == ExpressionType::keyPath) {
                $expressions->append($predicate->leftExpression);
            }
            if ($predicate->rightExpression->expressionType == ExpressionType::keyPath) {
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
     * @return ArrayClass<SQLProperty>
     */
    private function propertiesFromKeyPathExpression(Expression $expression): ArrayClass
    {
        /** @var ArrayClass<SQLProperty> $properties */
        $properties = new ArrayClass();
        if ($expression->expressionType !== ExpressionType::keyPath) {
            return $properties;
        }
        $entity = $this->entity;
        $keys = explode(".", $expression->description());
        foreach ($keys as $key) {
            $property = $entity->propertiesByName[$key];
            if ($property) {
                if ($property instanceof SQLRelationship) {
                    $entity = $property->destinationEntity;
                }
                $properties[] = $property;
                continue;
            }
            if ($this->raisesForNotApplicableKeys) {
                throw new UnknownKeyException(sprintf("%s does not contains a property named \"%s\"", $entity->tableName, $key));
            }
        }
        return $properties;
    }

    /**
     * @psalm-suppress LessSpecificReturnStatement, MoreSpecificReturnType
     * @param Expression $expression
     * @return ArrayClass<SQLRelationship>
     */
    private function relationshipsFromKeyPathExpression(Expression $expression): ArrayClass
    {
        return $this->propertiesFromKeyPathExpression($expression)->filter(fn(SQLProperty $property): bool => $property instanceof SQLRelationship); // @phpstan-ignore-line
    }

    private function isNullExpression(Expression $expression): bool
    {
        if ($expression->expressionType == ExpressionType::constantValue) {
            return $expression->constantValue() === null;
        } elseif ($expression->expressionType == ExpressionType::keyPath) {
            if ((new Value((string)$expression))->isEqual(null)) {
                throw new InvalidArgumentException("*isNullExpression($expression)*");
            }
        }
        return false;
    }

    private function isToManyCountKeyPath(Expression $expression): bool
    {
        return ($expression->expressionType === ExpressionType::keyPath) && count(explode(".", (string)$expression)) > 1;
    }

    private function buildKeyPathExpression(Expression $expression): string
    {
        $tableName = $this->entity->tableName;
        $alias = $tableName;
        $keyPath = $expression->description();
        $isToManyCountKeyPath = $this->isToManyCountKeyPath($expression);
        $properties = $this->propertiesFromKeyPathExpression($expression);
        foreach ($properties as $property) {
            if ($property instanceof SQLPrimaryKey || $property instanceof SQLEntityKey || $property instanceof SQLAttribute || $property instanceof SQLForeignKey) {
                $propertyDescription = $property->propertyDescription;
                if ($propertyDescription instanceof DerivedAttributeDescription && str_contains((string)$propertyDescription->derivationExpression, "@")) {
                    return $this->buildDerivedAttributeDescription($propertyDescription);
                }
                $alias .= ".";
                $alias .= $property->columnName;
            }
            if ($property instanceof SQLRelationship) {
                $alias .= "_";
                $alias .= $property->name;
                if ($property instanceof SQLToOne && !$isToManyCountKeyPath) {
                    $alias .= ".";
                    $alias .= $property->destinationEntity->primaryKey->columnName;
                }
            }
        }
        if ($alias === $tableName) {
            throw new InvalidArgumentException("Failed to generate alias for entity \"$tableName\", invalid key path \"$keyPath\"");
        }
        return $alias;
    }

    private function buildClauseWithSimplePredicate(ComparisonPredicate $predicate, string &$clause): void
    {
        switch ($predicate->predicateOperatorType) {
            case PredicateOperatorType::lessThan:
                $this->prepareClauseWithSimplePredicate($predicate, $clause, "<");
                break;
            case PredicateOperatorType::lessThanOrEqualTo:
                $this->prepareClauseWithSimplePredicate($predicate, $clause, "<=");
                break;
            case PredicateOperatorType::greaterThan:
                $this->prepareClauseWithSimplePredicate($predicate, $clause, ">");
                break;
            case PredicateOperatorType::greaterThanOrEqualTo:
                $this->prepareClauseWithSimplePredicate($predicate, $clause, ">=");
                break;
            case PredicateOperatorType::equalTo:
                $this->prepareEqual($predicate, $clause);
                break;
            case PredicateOperatorType::notEqualTo:
                $this->prepareNotEqual($predicate, $clause);
                break;
            case PredicateOperatorType::like:
            case PredicateOperatorType::matches:
                $this->prepareLike($predicate, $clause);
                break;
            case PredicateOperatorType::beginsWith:
                $this->prepareBeginsWith($predicate, $clause);
                break;
            case PredicateOperatorType::endsWith:
                $this->prepareEndsWith($predicate, $clause);
                break;
            case PredicateOperatorType::contains:
                $this->prepareContains($predicate, $clause);
                break;
            case PredicateOperatorType::in:
                $this->prepareIn($predicate, $clause);
                break;
            case PredicateOperatorType::between:
                $this->prepareBetween($predicate, $clause);
                break;
            default:
                break;
        }
    }

    private function buildComparisonExpression(Expression $expression, ArrayClass &$arguments, string $prefix = "", string $suffix = ""): mixed
    {
        if ($expression->expressionType == ExpressionType::keyPath) {
            return $this->buildKeyPathExpression($expression);
        } elseif ($expression->expressionType == ExpressionType::function) {
            return $this->buildFunctionExpression($expression);
        } elseif ($expression->expressionType == ExpressionType::conditional) {
            return $this->buildConditionalExpression($expression);
        } elseif ($expression->expressionType == ExpressionType::constantValue) {
            $constantValue = $expression->constantValue();
            if (is_string($constantValue)) {
                $constantValue = addcslashes($constantValue, "%_");
            } elseif (is_bool($constantValue)) {
                $constantValue = (int)$constantValue;
            } elseif ($constantValue instanceof ManagedObject) {
                $constantValue = $constantValue->objectID;
            }
            $argument = $constantValue;
            if (is_string($argument)) {
                $argument = "$prefix$argument$suffix";
                if (str_contains($argument, "\%") || str_contains($argument, "\_")) {
                    $constantValue = "?";
                }
            }
            $arguments[] = $argument;
            return $constantValue;
        } else {
            return $expression->description();
        }
    }

    private function prepareClauseWithSimplePredicate(ComparisonPredicate $predicate, string &$clause, string $operator, string $prefix = "", string $suffix = ""): void
    {
        /** @var ArrayClass $arguments */
        $arguments = new ArrayClass();
        $left = $this->buildComparisonExpression($predicate->leftExpression, $arguments, $prefix, $suffix);
        $right = $this->buildComparisonExpression($predicate->rightExpression, $arguments, $prefix, $suffix);
        $numberOfArguments = $arguments->count();
        if ($numberOfArguments === 1) {
            $key = $arguments->first();
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
        $right = $rightExpression->constantValue() ?? $rightExpression->collection();
        assert($right instanceof ArrayClass && !$right->isEmpty(), sprintf("invalid argument: the right expression of an IN operator must be an non-empty \"%s\", (%s)%s given", ArrayClass::class, typeof($right), human_readable_value($right)));
        $clause .= "{$this->buildKeyPathExpression($leftExpression)} IN (" . ArrayClass::repeating("?", $right->count())->join(", ") . ")";
        $this->arguments->appendContentsOf($right->map(fn(mixed $element): mixed => $element instanceof Expression ? $element->constantValue() : $element));
    }

    private function prepareBetween(ComparisonPredicate $predicate, string &$clause): void
    {
        $leftExpression = $predicate->leftExpression;
        $rightExpression = $predicate->rightExpression;
        $right = $rightExpression->constantValue() ?? $rightExpression->collection();
        assert($right instanceof ArrayClass && $right->count() == 2, sprintf("invalid argument: the right expression of a BETWEEN operator must be a \"%s\" with exactly two elements, (%s)%s given", ArrayClass::class, typeof($right), human_readable_value($right)));
        $clause .= "({$this->buildKeyPathExpression($leftExpression)} BETWEEN ? AND ?)";
        $this->arguments->appendContentsOf($right->map(fn(mixed $element): mixed => $element instanceof Expression ? $element->constantValue() : $element));
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
        $this->prepareClauseWithSimplePredicate($predicate, $clause, "!=");
    }

    private function prepareLike(ComparisonPredicate $predicate, string &$clause): void
    {
        $operator = "LIKE";
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
            $subpredicates = $predicate->subpredicates;
            $max = $subpredicates->indexBefore($subpredicates->endIndex());
            $type = $predicate->compoundPredicateType;
            if ($type == CompoundPredicateLogicalType::not) {
                $clause .= "NOT ";
            }
            if ($max) {
                $clause .= "(";
            }
            foreach ($subpredicates as $idx => $subpredicate) {
                $this->preparePredicate($subpredicate, $clause);
                if ($idx < $max) {
                    if ($type == CompoundPredicateLogicalType::and) {
                        $clause .= " AND ";
                    } elseif ($type == CompoundPredicateLogicalType::or) {
                        $clause .= " OR ";
                    }
                }
            }
            if ($max) {
                $clause .= ")";
            }
        } elseif ($predicate instanceof ComparisonPredicate) {
            $this->prepareComparisonPredicate($predicate, $clause);
        }
    }

    private function prepareComparisonPredicate(ComparisonPredicate $predicate, string &$clause): void
    {
        if ($predicate->comparisonPredicateModifier !== ComparisonPredicateModifier::direct) {
            if ($this->isToManyCountKeyPath($predicate->leftExpression)) {
                $this->buildClauseWithSelectPredicate($predicate, $clause);
            }
            if ($this->isToManyCountKeyPath($predicate->rightExpression)) {
                $this->buildClauseWithSelectPredicate($predicate, $clause);
            }
        } else {
            $this->buildClauseWithSimplePredicate($predicate, $clause);
        }
    }

    private function buildClauseWithSelectPredicate(ComparisonPredicate $predicate, string &$clause): void
    {
        $expressions = new ArrayClass([$predicate->leftExpression, $predicate->rightExpression]);
        if (!($expression = $expressions->first(fn(Expression $expression): bool => $expression->expressionType == ExpressionType::keyPath))) {
            throw new InvalidArgumentException();
        }
        $preparedExpression = $this->buildKeyPathExpression($expression);
        [$entityAlias, $columnName] = explode(".", $preparedExpression);
        $relationship = (function () use ($expression): ?SQLRelationship {
            $relationship = null;
            $entity = $this->entity;
            $keys = explode(".", $expression->keyPath());
            foreach ($keys as $key) {
                $property = $entity->propertiesByName[$key];
                if ($property instanceof SQLRelationship) {
                    $entity = $property->destinationEntity;
                    $relationship = $property;
                }
            }
            return $relationship;
        })() ?? throw new InvalidArgumentException();
        $destinationEntity = $relationship->destinationEntity;
        $clause .= "$preparedExpression = ";
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

    public function buildDerivedAttributeDescription(DerivedAttributeDescription $description): string
    {
        if ($expression = $description->derivationExpression) {
            switch ($expression->expressionType) {
                case ExpressionType::keyPath:
                    $keyPath = (string)$expression;
                    if (str_contains($keyPath, "@")) {
                        $entity = $this->entity;
                        [$keyPathToCollection, $collectionOperator, $keyPathToProperty] = kvc_components($keyPath);
                        if ($keyPathToCollection && $collectionOperator) {
                            /** @var SQLRelationship|null $relationship */
                            $relationship = $entity->propertiesByName[$keyPathToCollection];
                            if ($relationship instanceof SQLToMany || $relationship instanceof SQLManyToMany) {
                                $inverseRelationship = $relationship->inverseRelationship;
                                $destinationEntity = $relationship->destinationEntity;
                                if (($collectionOperator === KeyValueOperator::countKeyValueOperator && $keyPathToProperty) || ($collectionOperator !== KeyValueOperator::countKeyValueOperator && !$keyPathToProperty)) {
                                    throw new InvalidArgumentException("Invalid expression \"$expression\"");
                                }
                                /** @var ArrayClass<string|PropertyDescription> $propertiesToFetch */
                                $propertiesToFetch = new ArrayClass([$inverseRelationship->relationshipDescription]);
                                if ($collectionOperator !== KeyValueOperator::countKeyValueOperator) {
                                    $propertiesToFetch->append($keyPathToProperty);
                                }
                                $requestContext = $this->requestContext;
                                $managedObjectModel = $requestContext->sqlCore->persistentStoreCoordinator->managedObjectModel;
                                /** @var EntityDescription $entityForFetchRequest */
                                $entityForFetchRequest = $managedObjectModel->entitiesByName[$destinationEntity->tableName];
                                $fetchRequest = new FetchRequest();
                                $fetchRequest->entity = $entityForFetchRequest;
                                $fetchRequest->propertiesToFetch = $propertiesToFetch;
                                $fetchRequest->resultType = FetchRequestResultType::countResultType;
                                $generator = new SQLGenerator(new SQLFetchRequestContext($fetchRequest, $requestContext->context, $requestContext->sqlCore));
                                $generator->request = $fetchRequest;
                                $generator->autoDistinct = false;
                                $generator->raisesForNotApplicableKeys = false;
                                $generator->keyValueOperator = $collectionOperator;
                                $statement = $generator->statement() ?? throw new InvalidArgumentException();
                                $string = "($statement->string WHERE ";
                                if ($relationship instanceof SQLToMany) {
                                    $string .= "{$destinationEntity->tableName}_$inverseRelationship->name.{$entity->primaryKey->columnName} = $entity->tableName";
                                    if ($destinationEntity->isKindOfSQLEntity($entity)) {
                                        $string .= ".{$relationship->inverseToOne->foreignKey->columnName}";
                                    } else {
                                        $string .= ".{$entity->primaryKey->columnName}";
                                    }
                                } else {
                                    $string .= "{$destinationEntity->tableName}_$relationship->correlationTableName.$relationship->inverseColumnName = $entity->tableName.{$entity->primaryKey->columnName}";
                                }
                                return $string . ")";
                            }
                        }
                        throw new InvalidArgumentException("Invalid argument: unsupported expression \"$expression\"");
                    }
                    return $this->buildKeyPathExpression($expression);
                case ExpressionType::function:
                    return $this->buildFunctionExpression($expression);
                case ExpressionType::conditional:
                    return $this->buildConditionalExpression($expression);
                default:
                    throw new InvalidArgumentException("Invalid argument: unsupported expression \"$expression\"");
            }
        }
        throw new InvalidArgumentException("Invalid argument: invalid attribute \"$description\"");
    }

    private function buildFunctionExpression(Expression $expression): string
    {
        $arguments = $expression->arguments() ?? throw new InvalidArgumentException();
        $operator = $expression->operand();
        if ($operator instanceof ExpressionOperator) {
            switch ($operator->operatorType()) {
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
                    return "({$arguments->map(fn(Expression $argument): mixed => match ($argument->expressionType) {
                        ExpressionType::constantValue => (function () use ($argument): mixed {
                            $value = $argument->constantValue();
                            if ($value instanceof ArrayClass) {
                                return $value->sum();
                            }
                            return $value;
                        })(),
                        ExpressionType::function => $this->buildFunctionExpression($argument),
                        ExpressionType::conditional => $this->buildConditionalExpression($argument),
                        ExpressionType::aggregate => $argument->collection()->sum(),
                        default => $argument->description(),
                    })->join(" {$operator->operatorSymbol()} ")})";
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
                case ExpressionOperatorType::uuid:
                case ExpressionOperatorType::isNull:
                case ExpressionOperatorType::ifNull:
                case ExpressionOperatorType::nullIf:
                    $function = $operator->operatorSymbol();
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
                default:
                    throw new InvalidArgumentException("Unsupported expression \"$expression\"");
            }
            if (empty($function)) {
                throw new InvalidArgumentException("Invalid function");
            }
            $column = strtoupper($function);
            $column .= "(";
            $column .= $arguments->map(fn(Expression $expression): string => match ($expression->expressionType) {
                ExpressionType::constantValue => (function () use ($expression): string {
                    $value = $expression->constantValue();
                    if ($value instanceof ArrayClass) {
                        return $value->join(", ");
                    }
                    return $expression->description();
                })(),
                ExpressionType::keyPath => $this->buildKeyPathExpression($expression),
                ExpressionType::function => $this->buildFunctionExpression($expression),
                ExpressionType::conditional => $this->buildConditionalExpression($expression),
                ExpressionType::aggregate => $expression->collection()->join(", "),
                default => $expression->description(),
            })->join(match ($operator->operatorType()) {
                ExpressionOperatorType::cast => " AS ",
                default => ", ",
            });
            return $column . ")";
        }
        throw new InvalidArgumentException("Invalid argument: unsupported expression $expression");
    }

    private function buildConditionalExpression(Expression $expression): string
    {
        $predicate = "";
        $this->preparePredicate($expression->predicate(), $predicate);
        $true = $expression->true();
        if ($true->expressionType == ExpressionType::keyPath) {
            $true = $this->buildKeyPathExpression($true);
        } elseif ($true->expressionType == ExpressionType::function) {
            $true = $this->buildFunctionExpression($true);
        }
        $false = $expression->false();
        if ($false->expressionType == ExpressionType::keyPath) {
            $false = $this->buildKeyPathExpression($false);
        } elseif ($false->expressionType == ExpressionType::function) {
            $false = $this->buildFunctionExpression($false);
        }
        return "IF($predicate, $true, $false)";
    }

    private function buildGroupByClause(ArrayClass $propertiesToGroupBy): void
    {
        $this->appendGroupByClauseToSQL();
        $this->groupByClause .= $propertiesToGroupBy->map(fn(PropertyDescription $property): string => "{$this->entity->tableName}.$property->name")->join(", ");
    }

    private function buildOrderByClause(?ArrayClass $descriptors): void
    {
        $descriptors ??= new ArrayClass();
        $raisesForNotApplicableKeys = $this->raisesForNotApplicableKeys;
        $this->raisesForNotApplicableKeys = false;
        $expressions = $this->keyPathExpressionsForFetchRequestSerialization()->union($this->keyPathExpressionsForFetchRequestPredicate());
        /** @psalm-suppress InvalidArgument */
        $descriptors->appendContentsOf($expressions->flatMap(fn(Expression $expression): iterable => $this->relationshipsFromKeyPathExpression($expression)->compactMap(fn(SQLRelationship $relationship): ?SortDescriptor => $relationship instanceof SQLToMany && $relationship->isOrdered ? new SortDescriptor(sprintf("%s.%s", $expression->keyPath(), $relationship->inverseToOne->foreignOrderKey->columnName)) : null)));
        $this->raisesForNotApplicableKeys = $raisesForNotApplicableKeys;
        if (!$descriptors->isEmpty()) {
            $this->appendOrderByClauseToSQL();
            $this->orderByClause .= $descriptors->map(fn(SortDescriptor $descriptor): string => sprintf("%s %s", $this->buildKeyPathExpression(Expression::expressionForKeyPath($descriptor->key)), $descriptor->ascending ? "ASC" : "DESC"))->join(", ");
        }
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
        /** @var ArrayClass $arguments */
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
                        $value = $value->referenceObject;
                    }
                    $arguments->append($value);
                }
            }
        }
        $this->string = "INSERT INTO `$entity->tableName` (" . $columnNames->map(fn(string $columnName): string => "`$columnName`")->join(", ") . ") VALUES " . ArrayClass::repeating("(" . ArrayClass::repeating("?", $columnNames->count())->join(", ") . ")", $insertedObjects->count())->join(", ") . " ON DUPLICATE KEY UPDATE {$columnNames->map(fn(string $columnName): string => "`$columnName` = VALUES(`$columnName`)")->join(", ")}";
        $this->arguments = $arguments;
    }

    /**
     * @param SQLEntity $entity
     * @param ArrayClass<ManagedObject> $updatedObjects
     */
    private function prepareUpdateStatement(SQLEntity $entity, ArrayClass $updatedObjects): void
    {
        /** @var ArrayClass $arguments */
        $arguments = new ArrayClass();
        /** @var Set<string> $columnNames */
        $columnNames = new Set();
        foreach ($updatedObjects as $updatedObject) {
            foreach ($entity->properties as $property) {
                if ($property instanceof SQLAttribute || $property instanceof SQLToOne) {
                    $key = $property->name;
                    $value = $updatedObject->changedValuesForCurrentEvent()[$key];
                    if ($value !== null) {
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
        })->join(" ")} ELSE `$columnName` END)")->join(", ")} WHERE `{$entity->primaryKey->columnName}` IN (" . ArrayClass::repeating("?", $updatedObjects->count())->join(",") . ")";
        $arguments->appendContentsOf($updatedObjects->map(fn(ManagedObject $object): string|int => $object->objectID->referenceObject));
        $this->arguments = $arguments;
    }

    private function prepareDeleteStatement(SQLEntity $entity, ArrayClass $objects): void
    {
        $this->string = "DELETE FROM `$entity->tableName` WHERE `{$entity->primaryKey->columnName}` IN (" . ArrayClass::repeating("?", $objects->count())->join(",") . ")";
        $this->arguments = $objects;
    }

    private function prepareStatementForBatchUpdateRequest(): void
    {
        $this->string = "UPDATE `{$this->entity->tableName}`";
    }

    private function appendSetStatementForBatchUpdateRequest(BatchUpdateRequest $request): void
    {
        /** @var ArrayClass $arguments */
        $arguments = new ArrayClass();
        /** @var Dictionary $propertiesToUpdate */
        $propertiesToUpdate = $request->propertiesToUpdate;
        $this->string .= " SET {$propertiesToUpdate->map(function (mixed $value, string $key) use (&$arguments): string {
                if (is_string($value) && $this->entity->attributes->contains(fn(SQLAttribute $attribute): bool => string_contains($value, $attribute->name, CompareOptions::words))) {
                    return "`$key` = $value";
                }
                $arguments[] = $value;
                return "`$key` = ?";
            })->join(", ")}";
        $this->arguments = $arguments;
    }

    private function prepareStatementForBatchDeleteRequest(BatchDeleteRequest $request): void
    {
        /** @noinspection SqlWithoutWhere */
        $this->string = "DELETE `{$request->fetchRequest->entity->name}` FROM `{$request->fetchRequest->entity->name}`";
    }

    /**
     * @param Set $objects
     * @return Dictionary<ArrayClass>
     */
    private function groupedObjects(Set $objects): Dictionary
    {
        /** @var Dictionary<ArrayClass> $map */
        $map = new Dictionary();
        $object = $objects->first();
        if ($object instanceof PersistentHistoryTransaction) {
            $map[$object::className()] = new ArrayClass($objects);
        } else {
            $objects = $objects->sort(fn(ManagedObject $e0, ManagedObject $e1): int => $e0->entity->relationshipsByName->compactMap(fn(RelationshipDescription $relationship): ?EntityDescription => $e0->isRelationshipForKeyFault($relationship->name) ? $relationship->destinationEntity : null)->containsElement($e1->entity) ? ComparisonResult::orderedDescending->value : ComparisonResult::orderedAscending->value);
            foreach ($objects as $object) {
                $entity = $object->entity;
                $key = $entity->name;
                /** @var ArrayClass $value */
                $value = $map[$key] ?? new ArrayClass();
                if (!$value->containsElement($object)) {
                    $value->append($object);
                }
                $map[$key] = $value;
            }
        }
        return $map;
    }
}
