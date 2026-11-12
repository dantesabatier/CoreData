<?php

namespace Sabatier\CoreData;

use Exception;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
final class PersistentStoreSnapshotProvider implements SnapshotProvider
{
    private ManagedObjectContext $storeContext {
        get {
            $context = new ManagedObjectContext();
            $context->persistentStoreCoordinator = $this->context->persistentStoreCoordinator;
            return $context;
        }
    }

    public function __construct(private readonly ManagedObjectContext $context)
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
        $fetchRequest = $this->fetchRequest($object);
        $fetchRequest->propertiesToFetch = $properties;
        // A context of its own: the one being saved already holds this object, and fetching through it would answer with the in-memory values instead of the stored row.
        $snapshot = $this->storeContext->fetch($fetchRequest)->first?->originalSnapshot;
        return $snapshot?->filter(fn(mixed $value, string $key): bool => match ($key) {
            ManagedObjectObjectIDKey, ManagedObjectEntityNameKey, ManagedObjectVersionKey => true,
            default => $properties->containsElement($key),
        });
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
        if (!($snapshot = $this->snapshot($object, $properties))) {
            return null;
        }
        // An object carries no expression values, so those alone are read as a dictionary.
        $fetchRequest = $this->fetchRequest($object);
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        $fetchRequest->propertiesToFetch = $expressions;
        /** @var Dictionary<mixed>|null $values */
        $values = $this->context->fetch($fetchRequest)->first;
        $names = $expressions->map(fn(ExpressionDescription $expression): string => $expression->name);
        return $values ? $snapshot->merging($values->filter(fn(mixed $value, string $key): bool => $names->containsElement($key))) : $snapshot;
    }

    /**
     * @return FetchRequest<ManagedObject>
     */
    private function fetchRequest(ManagedObject $object): FetchRequest
    {
        /** @var FetchRequest<ManagedObject> $fetchRequest */
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = $object->entity;
        $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(ManagedObjectObjectIDKey), Expression::expressionForConstantValue($object->objectID->referenceObject));
        if ($store = $object->objectID->persistentStore) {
            $fetchRequest->affectedStores = new ArrayClass([$store]);
        }
        return $fetchRequest;
    }
}

