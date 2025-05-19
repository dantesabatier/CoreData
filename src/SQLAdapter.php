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
    public function __construct(public readonly SQLCore $sqlCore)
    {
    }

    private function generatedColumnExpression(Expression $expression, EntityDescription $entityDescription): string
    {
        $request = new FetchRequest();
        $request->entity = $entityDescription;
        $generator = new SQLGenerator(new SQLFetchRequestContext($request, new ManagedObjectContext(), $this->sqlCore));
        $expression = $generator->buildDerivationExpression($expression, isDeterministic: $isDeterministic);
        $generatedColumnType = $isDeterministic ? "PERSISTENT" : "VIRTUAL";
        return (string)(new SQLStatement("GENERATED ALWAYS AS ($expression) $generatedColumnType", $generator->arguments));
    }

    private function typeStringForColumn(SQLColumn $column): ?string
    {
        if ($column->isTransient) {
            return null;
        }
        $sqlType = $column->sqlType;
        $dataType = $sqlType->value;
        $length = $column->length;
        if ($column instanceof SQLPrimaryKey) {
            return "`$column->columnName` $dataType($length) UNSIGNED NOT NULL AUTO_INCREMENT";
        }
        if ($column instanceof SQLEntityKey) {
            $string = "`$column->columnName` $dataType($length) NOT NULL";
            if (!$column->entity->entityDescription->isPersistentHistoryEntity && $column->entity->subentities->count <= 1) {
                $string .= " DEFAULT '{$column->entity->tableName}'";
            }
            return $string;
        }
        if ($column instanceof SQLForeignKey) {
            return "`$column->columnName` $dataType($length) UNSIGNED";
        }
        if ($column instanceof SQLAttribute) {
            $attributeDescription = $column->attributeDescription;
            $string = "`$column->columnName` $dataType";
            if ($length && ($length = match ($attributeDescription->type) {
                    AttributeType::string => $attributeDescription->maxValue ?? 255,
                    AttributeType::uri => 600,
                    default => $length
                })) {
                $string .= "($length)";
            }
            if (($column->minValue !== null) && ($column->minValue >= 0) && ($unsigned = match ($sqlType) {
                    SQLType::smallint, SQLType::int, SQLType::bigint, SQLType::decimal, SQLType::float, SQLType::double => "UNSIGNED",
                    default => false
                })) {
                $string .= " $unsigned";
            }
            if ($expression = $column->derivationExpression) {
                if ($expression->usesKVC) {
                    return null;
                }
                return "$string {$this->generatedColumnExpression($expression, $column->entity->entityDescription)}";
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
        if ($values->count < 2) {
            return null;
        }
        /** @var ManagedObject $object */
        $object = $values->popFirst();
        $columnNames = new ArrayClass([$manyToMany->columnName, $manyToMany->inverseColumnName]);
        return SQLStatement::merging($values->map(fn(ManagedObject $e): SQLStatement => new SQLStatement("INSERT INTO `$manyToMany->correlationTableName` ({$columnNames->map(fn(string $columnName): string => "`$columnName`")->join(", ")}) VALUES (?, ?) ON DUPLICATE KEY UPDATE {$columnNames->map(fn(string $columnName): string => "`$columnName` = VALUES(`$columnName`)")->join(", ")}", new ArrayClass([$e->objectID, $object->objectID]))));
    }

    public function newCorrelationDeleteStatementForRelationship(SQLManyToMany $manyToMany, ArrayClass $values): ?SQLStatement
    {
        if ($values->count < 2) {
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
        $key = sprintf("FK_%s_%s", $destinationEntity->tableName, ucfirst($foreignKey->relationshipDescription->name));
        $statements->append($this->newDropIndexStatementForForeignKey($foreignKey, $entity));
        if ($toOneRelationship->inverseRelationship instanceof SQLToOne) {
            $statements->append(new SQLStatement("ALTER TABLE IF EXISTS `$destinationEntity->tableName` DROP FOREIGN KEY IF EXISTS `$key`"));
        }
        return $statements;
    }

    public function newDropIndexStatementForForeignKey(SQLForeignKey $foreignKey, ?SQLEntity $entity = null): SQLStatement
    {
        $entity ??= $foreignKey->entity;
        $key = sprintf("FK_%s_%s", $entity->tableName, ucfirst($foreignKey->relationshipDescription->name));
        return SQLStatement::merging(new ArrayClass([new SQLStatement("ALTER TABLE IF EXISTS `$entity->tableName` DROP FOREIGN KEY IF EXISTS `$key`"), new SQLStatement("ALTER TABLE IF EXISTS `$entity->tableName` DROP KEY IF EXISTS `$key`")]));
    }

    public function newCreateIndexStatementForForeignKey(SQLForeignKey $foreignKey, ?SQLEntity $entity = null): SQLStatement
    {
        $entity ??= $foreignKey->entity;
        $toOneRelationship = $foreignKey->toOneRelationship;
        $destinationEntity = $toOneRelationship->destinationEntity;
        $primaryKey = $destinationEntity->primaryKey;
        $key = sprintf("FK_%s_%s", $entity->tableName, ucfirst($foreignKey->relationshipDescription->name));
        /** @var ArrayClass<SQLStatement> $statements */
        $statements = new ArrayClass([new SQLStatement("ALTER TABLE `$entity->tableName` ADD CONSTRAINT `$key` FOREIGN KEY IF NOT EXISTS (`$foreignKey->columnName`) REFERENCES `$destinationEntity->tableName` (`$primaryKey->columnName`) ON UPDATE CASCADE ON DELETE " . match ($foreignKey->relationshipDescription->inverseRelationship->deleteRule) {
                DeleteRule::noActionDeleteRule => "NO ACTION",
                DeleteRule::nullifyDeleteRule => "SET NULL",
                DeleteRule::cascadeDeleteRule => "CASCADE",
                DeleteRule::denyDeleteRule => "RESTRICT"
            })]);
        if (SS_COREDATA_DISABLE_FOREIGN_KEY_CHECKS) :
            $statements->insertAt(new SQLStatement("/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */"), 0);
            $statements->append(new SQLStatement("/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */"));
        endif;
        return SQLStatement::merging($statements);
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
        if ($destination instanceof SQLAttribute && !$destination->isDerivedAttribute && !$destination->isTransient && $destination->defaultValue !== null && ($source->isOptional !== $destination->isOptional || $source->defaultValue !== $destination->defaultValue)) {
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

    public function newModifyColumnStatement(SQLColumn $column, SQLColumn $after): ?SQLStatement
    {
        if ($string = $this->typeStringForColumn($column)) {
            return new SQLStatement("ALTER IGNORE TABLE `{$column->entity->tableName}` MODIFY IF EXISTS $string AFTER `$after->columnName`");
        }
        return null;
    }

    public function newCreateColumnStatement(SQLColumn $column, ?SQLColumn $after = null): ?SQLStatement
    {
        if ($column->entity->byMappingByCompositeNameAssociationTable->offsetExists($column->columnName)) {
            return null;
        }
        if (!($string = $this->typeStringForColumn($column))) {
            return null;
        }
        return $after ? new SQLStatement("ALTER TABLE `{$column->entity->tableName}` ADD COLUMN IF NOT EXISTS $string AFTER `$after->columnName`") : new SQLStatement("ALTER TABLE `{$column->entity->tableName}` ADD COLUMN IF NOT EXISTS $string");
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
