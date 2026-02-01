<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SQLRelationshipFaultRequestContext extends SQLStoreRequestContext
{
    public function __construct(public readonly ManagedObjectID $objectID, public readonly RelationshipDescription $relationship, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct(new FetchRequest(), $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        $debugLevel = $this->debugLevel;
        $this->debugLevel = SQLDebugLevel::none;
        /** @var SQLEntity $entity */
        $entity = $this->sqlModel->entitiesByName[$this->objectID->entity->name] ?? fatal_error();
        $property = $entity->propertiesByName[$this->relationship->name] ?? fatal_error("$entity unable to find relationship {$this->relationship->name} in {$entity->propertiesByName->keys}");
        if ($property instanceof SQLToOne) {
            $sourceEntity = $property->entity;
            $foreignKey = $property->foreignKey;
            $columnName = $sourceEntity->primaryKey->columnName;
            $destinationEntity = $property->destinationEntity;
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
                $fetchRequest->includesPendingChanges = true;
                $fetchRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
                $this->result = $this->sqlCore->execute($fetchRequest, $this->context)->first ?? Nil::nil();
                $this->debugLevel = $debugLevel;
                return true;
            }
            $this->result = Nil::nil();
        } elseif ($property instanceof SQLToMany) {
            $inverseToOne = $property->inverseToOne;
            $destinationEntity = $property->destinationEntity;
            $columnName = $inverseToOne->foreignKey->columnName;
            /** @var FetchRequest<ManagedObjectID> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $destinationEntity->entityDescription;
            $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($columnName), Expression::expressionForConstantValue($this->objectID));
            $fetchRequest->includesPendingChanges = true;
            $fetchRequest->resultType = FetchRequestResultType::managedObjectIDResultType;
            $this->result = $this->sqlCore->execute($fetchRequest, $this->context);
        } elseif ($property instanceof SQLManyToMany) {
            /** @var FetchRequest<ManagedObject> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $entity->entityDescription;
            $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($this->objectID));
            $fetchRequest->propertiesToFetch = new ArrayClass([$property->relationshipDescription]);
            $fetchRequest->includesPendingChanges = true;
            $first = $this->sqlCore->execute($fetchRequest, $this->context)->first;
            if ($first instanceof ManagedObject) {
                $this->result = $first->primitiveValueForKey($property->name) ?? new ArrayClass();
            }
        }
        $this->debugLevel = $debugLevel;
        return true;
    }
}
