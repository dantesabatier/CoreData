<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
class SQLObjectFaultRequestContext extends SQLStoreRequestContext
{
    public readonly FetchRequest $fetchRequest;

    public function __construct(public readonly ManagedObjectID $objectID, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        $objectID = $this->objectID;
        /** @var SQLEntity $entity */
        $entity = $sqlCore->model->entitiesByName[$objectID->entityName];
        /** @var FetchRequest<Dictionary> $fetchRequest */
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $objectID->entity;
        /** @psalm-suppress InvalidArgument */
        $fetchRequest->predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($objectID)), new ComparisonPredicate(Expression::expressionForKeyPath($entity->entityKey->columnName), Expression::expressionForConstantValue($entity->tableName))]));
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        parent::__construct($fetchRequest, $context, $sqlCore);
        $this->fetchRequest = $fetchRequest;
    }

    public function createFetchRequestContext(): SQLFetchRequestContext
    {
        return new SQLFetchRequestContext($this->fetchRequest, $this->context, $this->sqlCore);
    }

    public function executeRequestCore(): bool
    {
        $context = $this->createFetchRequestContext();
        $context->executeRequestUsingConnection($this->connection);
        $this->result = $context->result->first();
        return true;
    }
}
