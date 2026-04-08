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

    public function __construct(public readonly ManagedObjectID $objectID, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        /** @var SQLEntity $entity */
        $entity = $sqlCore->model->entitiesByName[$this->objectID->entityName] ?? fatal_error("Entity not found: {$this->objectID->entityName}");
        $object = $context->object($this->objectID);
        /** @var FetchRequest<Dictionary> $fetchRequest */
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $this->objectID->entity;
        $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($this->objectID));
        $fetchRequest->propertiesToFetch = $object->modeledAttributes->values;
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        parent::__construct($fetchRequest, $context, $sqlCore);
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
