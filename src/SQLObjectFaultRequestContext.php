<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SQLObjectFaultRequestContext extends SQLStoreRequestContext
{
    public FetchRequest $fetchRequest {
        get {
            /** @var FetchRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }

    public function __construct(private readonly ManagedObjectID $objectID, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        /** @var SQLEntity $entity */
        $entity = $sqlCore->model->entitiesByName[$this->objectID->entityName] ?? fatal_error("Entity not found: {$this->objectID->entityName}");
        /** @var FetchRequest<Dictionary> $fetchRequest */
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $this->objectID->entity;
        $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($this->objectID->referenceObject));
        $fetchRequest->propertiesToFetch = $entity->entityDescription->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$attribute->isTransient && !$attribute instanceof CompositeAttributeDescription)->merging($entity->entityDescription->relationshipsByName->filter(fn(RelationshipDescription $relationship): bool => !$relationship->isToMany && !$relationship->inverseRelationship->isToMany))->map(fn(AttributeDescription|RelationshipDescription $description): string => $description->name);
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        parent::__construct($fetchRequest, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        $debugLevel = $this->debugLevel;
        $this->debugLevel = SQLDebugLevel::none;
        $context = new SQLFetchRequestContext($this->fetchRequest, $this->context, $this->sqlCore);
        $context->executeRequestUsingConnection($this->connection);
        $this->result = $context->result->first ?? fatal_error("Object not found: {$this->objectID->entityName} {$this->objectID->referenceObject}");
        $this->debugLevel = $debugLevel;
        return true;
    }
}
