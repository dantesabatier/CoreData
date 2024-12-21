<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use function Sabatier\Foundation\fatal_error;

/** @internal */
class SQLRelationshipFaultRequestContext extends SQLStoreRequestContext
{
    public function __construct(public readonly ManagedObjectID $objectID, public readonly RelationshipDescription $relationship, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct(new FetchRequest(), $context, $sqlCore);
    }

    #[Override]
    public function executeRequestCore(): bool
    {
        $debugLogLevel = $this->debugLogLevel;
        $this->debugLogLevel = 0;
        /** @var SQLEntity $entity */
        $entity = $this->sqlModel->entitiesByName[$this->objectID->entity->name];
        $relationship = $entity->entitySpecificRelationships->first(fn(SQLRelationship $relationship): bool => $relationship->relationshipDescription->name === $this->relationship->name) ?? fatal_error("Unable to find relationship {$this->relationship->name} in {$entity->entitySpecificRelationships->map(fn(SQLRelationship $relationship): string => $relationship->name)->description}");
        if ($relationship instanceof SQLToOne) {
            $sourceEntity = $relationship->entity;
            $foreignKey = $relationship->foreignKey;
            $columnName = $sourceEntity->primaryKey->columnName;
            $destinationEntity = $relationship->destinationEntity;
            if ($destinationEntity->isRootEntity && $destinationEntity->entityDescription->isAbstract && $destinationEntity->subentities->count === 1) {
                $destinationEntity = $destinationEntity->subentities[0];
            }
            /** @var SQLEntity $entity */
            $entity = $this->sqlModel->entity($entity->tableName);
            $statement = $this->sqlCore->queryGenerationTrackingConnection->execute(new SQLStatement("SELECT $entity->tableName.$foreignKey->columnName FROM `$entity->tableName` WHERE $entity->tableName.$columnName = ?", new ArrayClass([$this->objectID])));
            if ($referenceObject = $statement->fetchColumn()) {
                /** @var FetchRequest<ManagedObject> $fetchRequest */
                $fetchRequest = new FetchRequest();
                $fetchRequest->entity = $destinationEntity->entityDescription;
                $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($referenceObject));
                $fetchRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
                $this->result = $this->sqlCore->execute($fetchRequest, $this->context)->first;
                return true;
            }
            $this->result = null;
        } elseif ($relationship instanceof SQLToMany) {
            $inverseToOne = $relationship->inverseToOne;
            $destinationEntity = $relationship->destinationEntity;
            $columnName = $inverseToOne->foreignKey->columnName;
            /** @var FetchRequest<ManagedObjectID> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $destinationEntity->entityDescription;
            $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($columnName), Expression::expressionForConstantValue($this->objectID));
            $fetchRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
            $this->result = $this->sqlCore->execute($fetchRequest, $this->context);
        } elseif ($relationship instanceof SQLManyToMany) {
            /** @var FetchRequest<ManagedObject> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $entity->entityDescription;
            $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($this->objectID));
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $fetchRequest->propertiesToFetch = new ArrayClass([$relationship->relationshipDescription]);
            $first = $this->sqlCore->execute($fetchRequest, $this->context)->first;
            if ($first instanceof ManagedObject) {
                $this->result = $first->primitiveValueForKey($relationship->name) ?? new ArrayClass();
            }
        }
        $this->debugLogLevel = $debugLogLevel;
        return true;
    }
}
