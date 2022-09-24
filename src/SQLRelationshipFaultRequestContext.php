<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonPredicate;
use Sabatier\Foundation\CompoundPredicate;
use Sabatier\Foundation\Expression;

/** @internal */
class SQLRelationshipFaultRequestContext extends SQLStoreRequestContext
{
    public readonly SQLModel $sqlModel;

    public function __construct(public readonly ManagedObjectID $objectID, public readonly RelationshipDescription $relationship, SQLCore $sqlCore)
    {
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $sqlCore->persistentStoreCoordinator;
        parent::__construct(new FetchRequest(), $context, $sqlCore);
        $this->sqlModel = $sqlCore->model;
    }

    public function executeRequestCore(): bool
    {
        $objectID = $this->objectID;
        /** @var SQLEntity $entity */
        $entity = $this->sqlModel->entitiesByName[$objectID->entity->name];
        $relationship = $entity->entitySpecificRelationships->first(fn(SQLRelationship $relationship): bool => $relationship->relationshipDescription === $this->relationship);
        if ($relationship instanceof SQLToOne) {
            $sourceEntity = $relationship->entity;
            $foreignKey = $relationship->foreignKey;
            $columnName = $sourceEntity->primaryKey->columnName;
            $destinationEntity = $relationship->destinationEntity;
            if ($destinationEntity->isRootEntity && $destinationEntity->subentities->count() === 1) {
                $destinationEntity = $destinationEntity->subentities[0];
            }
            /** @var SQLEntity $entity */
            $entity = $this->sqlModel->entity($entity->tableName);
            $statement = $this->sqlCore->queryGenerationTrackingConnection->execute(new SQLStatement("SELECT $entity->tableName.$foreignKey->columnName FROM $entity->tableName WHERE $entity->tableName.$columnName = ?", new ArrayClass([$objectID])));
            /** @var FetchRequest<ManagedObject> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $destinationEntity->entityDescription;
            if ($referenceObject = $statement->fetchColumn()) {
                /** @psalm-suppress InvalidArgument */
                $fetchRequest->predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($this->sqlCore->newObjectID($destinationEntity->entityDescription, $referenceObject))), new ComparisonPredicate(Expression::expressionForKeyPath($entity->entityKey->columnName), Expression::expressionForConstantValue($destinationEntity->tableName))]));
                // FIXME: by default a fetch request will ask for all of the attributes in an entity, to avoid problems during data migration we must set the properties to fetch to an empty array, we need to check if this is a solution or a hack
                $fetchRequest->propertiesToFetch = new ArrayClass();
                $this->result = $this->sqlCore->execute($fetchRequest, $this->context)->first();
                return true;
            }
            $this->result = null;
        } elseif ($relationship instanceof SQLToMany) {
            $inverseToOne = $relationship->inverseToOne;
            $destinationEntity = $relationship->destinationEntity;
            $columnName = $inverseToOne->foreignKey->columnName;
            $entityKey = $destinationEntity->entityKey;
            /** @var FetchRequest<ManagedObjectID> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $destinationEntity->entityDescription;
            /** @psalm-suppress InvalidArgument */
            $fetchRequest->predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([new ComparisonPredicate(Expression::expressionForKeyPath($columnName), Expression::expressionForConstantValue($objectID)), new ComparisonPredicate(Expression::expressionForKeyPath($entityKey->columnName), Expression::expressionForConstantValue($destinationEntity->tableName))]));
            $fetchRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
            $this->result = $this->sqlCore->execute($fetchRequest, $this->context);
        } elseif ($relationship instanceof SQLManyToMany) {
            /** @var FetchRequest<ManagedObject> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $entity->entityDescription;
            /** @psalm-suppress InvalidArgument */
            $fetchRequest->predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($objectID)), new ComparisonPredicate(Expression::expressionForKeyPath($entity->entityKey->columnName), Expression::expressionForConstantValue($entity->tableName))]));
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $fetchRequest->propertiesToFetch = new ArrayClass([$relationship->relationshipDescription]); // @phpstan-ignore-line
            $first = $this->sqlCore->execute($fetchRequest, $this->context)->first();
            if ($first instanceof ManagedObject) {
                $this->result = $first->primitiveValueForKey($relationship->name) ?? new ArrayClass();
            }
        }
        return true;
    }
}