<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
class SQLAdapter extends ObjectClass
{
    public readonly SQLModel $model;

    public function __construct(public readonly SQLCore $sqlCore)
    {
        unset($this->model);
    }

    public function __get(string $name)
    {
        return $this->$name = match ($name) {
            "model" => $this->sqlCore->model,
            default => $this->valueForUndefinedKey($name)
        };
    }

    private function generatedColumnExpression(SQLAttribute $attribute): ?string
    {
        if (($expression = $attribute->derivationExpression) && !$expression->usesKVC) {
            $request = new FetchRequest();
            $request->entity = $attribute->entity->entityDescription;
            $generator = new SQLGenerator(new SQLFetchRequestContext($request, new ManagedObjectContext(), $this->sqlCore));
            $expression = $generator->buildDerivationExpression($expression, isDeterministic: $isDeterministic);
            $generatedColumnType = $isDeterministic ? "PERSISTENT" : "VIRTUAL";
            return (string)(new SQLStatement("GENERATED ALWAYS AS ($expression) $generatedColumnType", $generator->arguments));
        }
        return null;
    }

    private function typeStringForColumn(SQLColumn $column): ?string
    {
        $sqlType = $column->sqlType;
        $dataType = $sqlType->value;
        $length = $column->length;
        if ($column instanceof SQLPrimaryKey) {
            return "`$column->columnName` $dataType($length) UNSIGNED NOT NULL AUTO_INCREMENT";
        } elseif ($column instanceof SQLEntityKey) {
            $string = "`$column->columnName` $dataType($length) NOT NULL";
            if (!$column->entity->entityDescription->isPersistentHistoryEntity && $column->entity->subentities->count <= 1) {
                $string .= " DEFAULT '{$column->entity->tableName}'";
            }
            return $string;
        } elseif ($column instanceof SQLForeignKey) {
            return "`$column->columnName` $dataType($length) UNSIGNED";
        } elseif ($column instanceof SQLAttribute) {
            $attributeDescription = $column->attributeDescription;
            $string = "`$column->columnName` $dataType";
            if ($length && ($length = match ($attributeDescription->type) {
                    AttributeType::string => $attributeDescription->maxValue ?? 255,
                    AttributeType::uri => 600,
                    AttributeType::transformable, AttributeType::objectID => 9999,
                    default => $length
                })) {
                $string .= "($length)";
            }
            if (($column->minValue >= 0) && ($unsigned = match ($sqlType) {
                    SQLType::smallint, SQLType::int, SQLType::bigint, SQLType::decimal, SQLType::float, SQLType::double => "UNSIGNED",
                    default => false
                })) {
                $string .= " $unsigned";
            }
            if ($generatedColumnExpression = $this->generatedColumnExpression($column)) {
                return "$string $generatedColumnExpression";
            }
            if ($column->isOptional) {
                if ($sqlType === SQLType::timestamp) {
                    $string .= " NULL DEFAULT NULL";
                }
            } else {
                $string .= " NOT NULL";
                $defaultValue = $column->defaultValue;
                if ($defaultValue !== null && $defaultValue !== "") {
                    $string .= " DEFAULT $defaultValue";
                }
            }
            return $string;
        }
        return null;
    }

    public function newCorrelationInsertStatementForRelationship(SQLManyToMany $manyToMany, ArrayClass $values): ?SQLStatement
    {
        if ($values->isEmpty) {
            return null;
        }
        /** @var ManagedObject $object */
        $object = $values->popFirst();
        $columnNames = new ArrayClass([$manyToMany->columnName, $manyToMany->inverseColumnName]);
        return SQLStatement::merging($values->map(fn(ManagedObject $e): SQLStatement => new SQLStatement("INSERT INTO `$manyToMany->correlationTableName` ({$columnNames->map(fn(string $columnName): string => "`$columnName`")->join(", ")}) VALUES (?, ?) ON DUPLICATE KEY UPDATE {$columnNames->map(fn(string $columnName): string => "`$columnName` = VALUES(`$columnName`)")->join(", ")}", new ArrayClass([$e->objectID, $object->objectID]))));
    }

    public function newCorrelationDeleteStatementForRelationship(SQLManyToMany $manyToMany, ArrayClass $values): ?SQLStatement
    {
        if ($values->isEmpty) {
            return null;
        }
        /** @var ManagedObject $object */
        $object = $values->popFirst();
        $columnNames = new ArrayClass([$manyToMany->columnName, $manyToMany->inverseColumnName]);
        return SQLStatement::merging($values->map(fn(ManagedObject $e): SQLStatement => new SQLStatement("DELETE FROM `$manyToMany->correlationTableName` WHERE {$columnNames->map(fn(string $columnName): string => "`$columnName` = ?")->join(" AND ")}", new ArrayClass([$e->objectID, $object->objectID]))));
    }

    public function newCorrelationReorderStatementForRelationship(SQLManyToMany $manyToMany, ArrayClass $values): ?SQLStatement
    {
        return $this->newCorrelationInsertStatementForRelationship($manyToMany, $values);
    }

    /**
     * @param SQLForeignKey $foreignKey
     * @param SQLEntity|null $entity
     * @return ArrayClass<SQLStatement>
     */
    public function newDropIndexStatementsForForeignKey(SQLForeignKey $foreignKey, ?SQLEntity $entity = null): ArrayClass
    {
        $entity ??= $foreignKey->entity;
        /** @var ArrayClass<SQLStatement> $statements */
        $statements = new ArrayClass();
        $toOneRelationship = $foreignKey->toOneRelationship;
        $destinationEntity = $toOneRelationship->destinationEntity;
        $statements->append($this->newDropIndexStatementForForeignKey($foreignKey, $entity));
        if ($toOneRelationship->inverseRelationship instanceof SQLToOne) {
            $statements->append(new SQLStatement("ALTER TABLE IF EXISTS `$destinationEntity->tableName` DROP FOREIGN KEY IF EXISTS FK_{$destinationEntity->tableName}_$entity->tableName"));
        }
        return $statements;
    }

    public function newDropIndexStatementForForeignKey(SQLForeignKey $foreignKey, ?SQLEntity $entity = null): SQLStatement
    {
        $entity ??= $foreignKey->entity;
        return new SQLStatement("ALTER TABLE IF EXISTS `$entity->tableName` DROP FOREIGN KEY IF EXISTS FK_{$entity->tableName}_{$foreignKey->toOneRelationship->foreignEntityKey->name}");
    }

    public function newCreateIndexStatementForForeignKey(SQLForeignKey $foreignKey, ?SQLEntity $entity = null): SQLStatement
    {
        $entity ??= $foreignKey->entity;
        $toOneRelationship = $foreignKey->toOneRelationship;
        $destinationEntity = $toOneRelationship->destinationEntity;
        $primaryKey = $destinationEntity->primaryKey;
        return new SQLStatement("ALTER TABLE `$entity->tableName` ADD CONSTRAINT FK_{$entity->tableName}_{$toOneRelationship->foreignEntityKey->name} FOREIGN KEY IF NOT EXISTS (`$foreignKey->columnName`) REFERENCES `$destinationEntity->tableName` (`$primaryKey->columnName`) ON UPDATE CASCADE ON DELETE " . match ($foreignKey->relationshipDescription->inverseRelationship->deleteRule) {
                DeleteRule::noActionDeleteRule => "NO ACTION",
                DeleteRule::nullifyDeleteRule => "SET NULL",
                DeleteRule::cascadeDeleteRule => "CASCADE",
                DeleteRule::denyDeleteRule => "RESTRICT"
            });
    }

    public function newDropIndexStatement(SQLColumn $column): ?SQLStatement
    {
        if ($column instanceof SQLForeignKey) {
            return SQLStatement::merging($this->newDropIndexStatementsForForeignKey($column));
        }
        if ($index = $column->entity->indexes->first(fn(SQLIndex $index): bool => $index->indexDescription->elements->contains(fn(FetchIndexElementDescription $element): bool => $element->property->isEqual($column->propertyDescription)))) {
            return SQLStatement::merging($index->dropTableStatements);
        }
        return null;
    }

    public function newCreateIndexStatement(SQLColumn $column): ?SQLStatement
    {
        if ($column instanceof SQLForeignKey) {
            return $this->newCreateIndexStatementForForeignKey($column);
        }
        if ($index = $column->entity->indexes->first(fn(SQLIndex $index): bool => $index->indexDescription->elements->contains(fn(FetchIndexElementDescription $element): bool => $element->property->isEqual($column->propertyDescription)))) {
            return SQLStatement::merging($index->createTableStatements);
        }
        return null;
    }

    public function newDropColumnStatement(SQLColumn $column): SQLStatement
    {
        return new SQLStatement("ALTER TABLE `{$column->entity->tableName}` DROP COLUMN IF EXISTS `$column->columnName`");
    }

    public function newRenameColumnStatement(SQLColumn $source, SQLColumn $destination): ?SQLStatement
    {
        $entity = $destination->entity;
        if ($source->name !== $destination->name) {
            /** @noinspection SqlIdentifier */
            return new SQLStatement("ALTER TABLE `$entity->tableName` RENAME COLUMN IF EXISTS `$source->columnName` TO `$destination->columnName`");
        }
        if ($destination instanceof SQLAttribute && !$destination->isDerivedAttribute && $destination->defaultValue !== null && ($source->isOptional !== $destination->isOptional || $source->defaultValue !== $destination->defaultValue)) {
            $request = new BatchUpdateRequest($entity->entityDescription);
            $request->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($destination->columnName), Expression::expressionForConstantValue(null));
            $request->propertiesToUpdate = new Dictionary([$destination->columnName => $destination->defaultValue]);
            $requestContext = new SQLBatchUpdateRequestContext($request, new ManagedObjectContext(), $this->sqlCore);
            /** @noinspection PhpUnhandledExceptionInspection */
            $requestContext->executeRequestUsingConnection($this->sqlCore->schemaValidationConnection);
        }
        if ($string = $this->typeStringForColumn($destination)) {
            return new SQLStatement("ALTER TABLE `$entity->tableName` MODIFY IF EXISTS $string");
        }
        return null;
    }

    public function newCreateColumnStatement(SQLColumn $column, SQLColumn $after): ?SQLStatement
    {
        if ($string = $this->typeStringForColumn($column)) {
            return new SQLStatement("ALTER TABLE `{$column->entity->tableName}` ADD COLUMN IF NOT EXISTS $string AFTER `$after->columnName`");
        }
        return null;
    }

    public function newDropTableStatementForManyToMany(SQLManyToMany $manyToMany): SQLStatement
    {
        return new SQLStatement("DROP TABLE IF EXISTS `$manyToMany->correlationTableName`");
    }

    public function newDropTableStatement(SQLEntity $entity): SQLStatement
    {
        return new SQLStatement("DROP TABLE IF EXISTS `$entity->tableName`");
    }

    public function newDropIndexesStatementForManyToMany(SQLManyToMany $manyToMany): SQLStatement
    {
        $entities = new ArrayClass([$manyToMany->destinationEntity, $manyToMany->inverseRelationship->destinationEntity]);
        return SQLStatement::merging($entities->map(fn(SQLEntity $destinationEntity): SQLStatement => new SQLStatement("ALTER TABLE IF EXISTS `$manyToMany->correlationTableName` DROP FOREIGN KEY IF EXISTS `FK_{$manyToMany->correlationTableName}__{$manyToMany->entity->tableName}_$destinationEntity->tableName`")));
    }

    public function newDropIndexesStatement(SQLEntity $entity): ?SQLStatement
    {
        /** @var ArrayClass<SQLStatement> $statements */
        $statements = $entity->indexes->flatMap(fn(SQLIndex $index): ArrayClass => $index->dropTableStatements);
        $statements->appendContentsOf($entity->foreignKeyColumns->flatMap(fn(SQLForeignKey $foreignKey): ArrayClass => $this->newDropIndexStatementsForForeignKey($foreignKey, $entity)));
        if (!$statements->isEmpty) {
            return SQLStatement::merging($statements);
        }
        return null;
    }

    public function newCreateIndexesStatementForManyToMany(SQLManyToMany $manyToMany): SQLStatement
    {
        /** @var ArrayClass<SQLStatement> $statements */
        $statements = new ArrayClass();
        $sourceEntity = $manyToMany->entity;
        $correlationTableName = $manyToMany->correlationTableName;
        $destinationEntity = $manyToMany->destinationEntity;
        $primaryKey = $destinationEntity->primaryKey;
        $statements->append(new SQLStatement("ALTER TABLE `$correlationTableName` ADD CONSTRAINT `FK_{$correlationTableName}__{$sourceEntity->tableName}_$destinationEntity->tableName` FOREIGN KEY IF NOT EXISTS (`$manyToMany->columnName`) REFERENCES `$destinationEntity->tableName` (`$primaryKey->columnName`) ON UPDATE CASCADE ON DELETE CASCADE"));
        $sourceEntity = $destinationEntity;
        $destinationEntity = $manyToMany->inverseRelationship->destinationEntity;
        $primaryKey = $destinationEntity->primaryKey;
        $statements->append(new SQLStatement("ALTER TABLE `$correlationTableName` ADD CONSTRAINT `FK_{$correlationTableName}__{$sourceEntity->tableName}_$destinationEntity->tableName` FOREIGN KEY IF NOT EXISTS (`$manyToMany->inverseColumnName`) REFERENCES `$destinationEntity->tableName` (`$primaryKey->columnName`) ON UPDATE CASCADE ON DELETE CASCADE"));
        return SQLStatement::merging($statements);
    }

    public function newCreateIndexesStatement(SQLEntity $entity): ?SQLStatement
    {
        /** @var ArrayClass<SQLStatement> $statements */
        $statements = $entity->indexes->flatMap(fn(SQLIndex $index): ArrayClass => $index->createTableStatements);
        $statements->appendContentsOf($entity->foreignKeyColumns->map(fn(SQLForeignKey $foreignKey): SQLStatement => $this->newCreateIndexStatementForForeignKey($foreignKey, $entity)));
        if (!$statements->isEmpty) {
            return SQLStatement::merging($statements);
        }
        return null;
    }

    public function newRenameTableStatement(SQLEntity $sourceEntity, SQLEntity $destinationEntity): SQLStatement
    {
        return new SQLStatement("RENAME TABLE IF EXISTS `$sourceEntity->tableName` TO `$destinationEntity->tableName`");
    }

    public function newCreateTableStatementForManyToMany(SQLManyToMany $manyToMany): SQLStatement
    {
        $columnNames = new ArrayClass([$manyToMany->orderColumnName, $manyToMany->inverseOrderColumnName]);
        return new SQLStatement("CREATE TABLE IF NOT EXISTS `$manyToMany->correlationTableName` ({$columnNames->map(fn (string $columnName): string => "`$columnName` {$manyToMany->columnSQLType->value}(11) UNSIGNED")->join(", ")}, CONSTRAINT PRIMARY KEY ({$columnNames->map(fn(string $columnName): string => "`$columnName`")->join(", ")}) USING BTREE) ENGINE={$this->sqlCore->schemaValidationConnection->schema->engine} DEFAULT CHARSET={$this->sqlCore->schemaValidationConnection->schema->charset} COLLATE={$this->sqlCore->schemaValidationConnection->schema->collation}");
    }

    public function newCreateTableStatement(SQLEntity $entity): SQLStatement
    {
        return new SQLStatement("CREATE TABLE IF NOT EXISTS `$entity->tableName` ({$entity->columnsToCreate->compactMap(fn(SQLColumn $column): ?string => $this->typeStringForColumn($column))->join(", ")}, CONSTRAINT PK_{$entity->primaryKey->columnName} PRIMARY KEY (`{$entity->primaryKey->columnName}`) USING BTREE) ENGINE={$this->sqlCore->schemaValidationConnection->schema->engine} DEFAULT CHARSET={$this->sqlCore->schemaValidationConnection->schema->charset} COLLATE={$this->sqlCore->schemaValidationConnection->schema->collation}");
    }
}
