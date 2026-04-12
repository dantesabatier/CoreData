<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SQLBatchFaultRequestContext extends SQLStoreRequestContext
{
    public FetchRequest $fetchRequest {
        get {
            /** @var FetchRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }

    /**
     * @param ArrayClass<ManagedObjectID> $objectIDs
     * @param ManagedObjectContext $context
     * @param SQLCore $sqlCore
     */
    public function __construct(private readonly ArrayClass $objectIDs, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        $objectID = $this->objectIDs->first ?? fatal_error("Invalid argument: objectIDs is empty or invalid");
        /** @var SQLEntity $entity */
        $entity = $sqlCore->model->entitiesByName[$objectID->entityName] ?? fatal_error("Entity not found: $objectID->entityName");
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $entity->entityDescription;
        $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($this->objectIDs), PredicateOperatorType::in);
        $fetchRequest->propertiesToFetch = $entity->entityDescription->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$attribute->isTransient && !$attribute instanceof CompositeAttributeDescription)->merging($entity->entityDescription->relationshipsByName->filter(fn(RelationshipDescription $relationship): bool => !$relationship->isToMany && !$relationship->inverseRelationship->isToMany))->map(fn(AttributeDescription|RelationshipDescription $description): string => $description->name);
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        parent::__construct($fetchRequest, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        $context = new SQLFetchRequestContext($this->fetchRequest, $this->context, $this->sqlCore);
        $context->executeRequestUsingConnection($this->connection);
        $this->result = $context->result;
        return true;
    }
}
