<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use function Sabatier\Foundation\fatal_error;

/** @internal */
class SQLObjectFaultRequestContext extends SQLStoreRequestContext
{
    public FetchRequest $fetchRequest {
        get {
            /** @var FetchRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    public readonly ManagedObjectID $objectID;

    public function __construct(ManagedObjectID $objectID, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        /** @var SQLEntity $entity */
        $entity = $sqlCore->model->entitiesByName[$objectID->entityName] ?? fatal_error("Entity not found: $objectID->entityName");
        /** @var FetchRequest<Dictionary> $fetchRequest */
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $objectID->entity;
        $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($objectID));
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        parent::__construct($fetchRequest, $context, $sqlCore);
        $this->objectID = $objectID;
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        $context = new SQLFetchRequestContext($this->fetchRequest, $this->context, $this->sqlCore);
        $context->executeRequestUsingConnection($this->connection);
        $this->result = $context->result->first;
        return true;
    }
}
