<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SQLObjectIDSetFetchRequestContext extends SQLFetchRequestContext
{
    public readonly ArrayClass $idSets;
    public readonly string $columnName;

    public function __construct(FetchRequest $request, ManagedObjectContext $context, SQLCore $sqlCore, ArrayClass $idSets, string $columnName)
    {
    	/** @var string $entityName */
    	$entityName = $request->entity?->name;
    	/** @var SQLEntity $entity */
    	$entity = $sqlCore->model->entitiesByName[$entityName] ?? fatal_error("Entity not found for relationship fault: $entityName");
    	/** @var FetchRequest<ManagedObjectID> $fetchRequest */
    	$fetchRequest = clone($request, [
    		"predicate" => new ComparisonPredicate(Expression::expressionForKeyPath($entity->primaryKey->columnName), Expression::expressionForConstantValue($idSets), PredicateOperatorType::in),
      		"sortDescriptors" => new ArrayClass([new SortDescriptor($columnName)]),
        	"resultType" => FetchRequestResultType::managedObjectIDResultType,
    	]);
        parent::__construct($fetchRequest, $context, $sqlCore);
        $this->idSets = $idSets;
        $this->columnName = $columnName;
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
   		$debugLevel = $this->debugLevel;
   		$this->debugLevel = SQLDebugLevel::none;
   		parent::executeRequestCore();
   		$this->debugLevel = $debugLevel;
        return true;
    }
}
