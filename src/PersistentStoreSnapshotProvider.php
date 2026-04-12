<?php

namespace Sabatier\CoreData;

use Exception;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
final readonly class PersistentStoreSnapshotProvider implements SnapshotProvider
{
    public function __construct(private ManagedObjectContext $context)
    {
    }

    /**
     * @param ManagedObject $object
     * @param ArrayClass<string> $properties
     * @return Dictionary<mixed>|null
     * @throws Exception
     */
    #[Override]
    public function snapshot(ManagedObject $object, ArrayClass $properties): ?Dictionary
    {
        return $this->executeFetch($object, $properties, null);
    }

    /**
     * @param ManagedObject $object
     * @param ArrayClass<string> $properties
     * @param ArrayClass<ExpressionDescription> $expressions
     * @return Dictionary<mixed>|null
     * @throws Exception
     */
    #[Override]
    public function snapshotWithExpressions(ManagedObject $object, ArrayClass $properties, ArrayClass $expressions): ?Dictionary
    {
        return $this->executeFetch($object, $properties, $expressions);
    }

    /**
     * @param ManagedObject $object
     * @param ArrayClass<string> $properties
     * @param ArrayClass<ExpressionDescription>|null $expressions
     * @return Dictionary<mixed>|null
     * @throws Exception
     */
    private function executeFetch(ManagedObject $object, ArrayClass $properties, ?ArrayClass $expressions): ?Dictionary
    {
        /** @var FetchRequest<Dictionary<mixed>> $fetchRequest */
        $fetchRequest = $object::fetchRequest();
        $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(ManagedObjectObjectIDKey), Expression::expressionForConstantValue($object->objectID->referenceObject));
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        if ($store = $object->objectID->persistentStore) {
            $fetchRequest->affectedStores = new ArrayClass([$store]);
        }
        $fetchRequest->propertiesToFetch = $expressions ? new ArrayClass([...$properties, ...$expressions]) : $properties;
        return $this->context->fetch($fetchRequest)->first;
    }
}

