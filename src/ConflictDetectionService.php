<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final readonly class ConflictDetectionService
{
    public function __construct(private SnapshotProvider $snapshotProvider, private VersioningStrategy $versioningStrategy, private DeleteRuleConflictDetector $deleteRuleDetector, private MergePolicy $mergePolicy)
    {
    }

    /**
     * @throws Exception
     */
    public function detectConflicts(ManagedObject $object): void
    {
        if ($object->objectID->isTemporaryID) {
            return;
        }
        if (!$object->originalSnapshot) {
            return;
        }
        $baselineSnapshot = $this->baselineSnapshotFor($object);
        $snapshotKeys = $baselineSnapshot->keys;
        if ($object->isDeleted) {
            $this->mergePolicy->resolveConflicts($this->deleteRuleDetector->conflictsForDeletion($object, $baselineSnapshot));
            return;
        }
        if ($object->isUpdated) {
            $storeSnapshot = $this->snapshotProvider->snapshot($object, $snapshotKeys);
            if ($storeSnapshot && $this->versioningStrategy->hasConflict($baselineSnapshot, $storeSnapshot)) {
                $this->mergePolicy->resolveOptimisticLockingVersionConflicts(new ArrayClass([new MergeConflict($object, $storeSnapshot[ManagedObjectVersionKey], $baselineSnapshot[ManagedObjectVersionKey], $baselineSnapshot, $storeSnapshot)]));
            }
        }
    }

    /**
     * @throws Exception
     */
    public function detectConstraintConflicts(ManagedObject $object): void
    {
        if ($object->objectID->isTemporaryID) {
            return;
        }
        if (!$object->originalSnapshot) {
            return;
        }
        $changedValues = $object->changedValuesForCurrentEvent();
        !$changedValues->isEmpty ?: fatal_error("Attempting to save an object with no changes $object");
        $baselineSnapshot = $this->baselineSnapshotFor($object);
        $snapshotKeys = $baselineSnapshot->keys;
        $conflicts = $changedValues->compactMap(fn(mixed $value, string $key): ?ConstraintConflict => $this->findConstraintConflict($object, $key, $value, $snapshotKeys, $baselineSnapshot));
        if ($conflicts->isEmpty) {
            return;
        }
        $this->mergePolicy->resolveConstraintConflicts($conflicts);
    }

    /**
     * @param ManagedObject $object
     * @return Dictionary<mixed>
     */
    private function baselineSnapshotFor(ManagedObject $object): Dictionary
    {
        return $object->originalSnapshot?->filter(fn(mixed $value, string $key): bool => match ($key) {
            ManagedObjectObjectIDKey, ManagedObjectEntityNameKey, ManagedObjectVersionKey => true,
            default => $object->modeledAttributes->offsetExists($key) && !$object->transientProperties->offsetExists($key),
        }) ?? new Dictionary();
    }

    private function isUniqueIndexedAttribute(ManagedObject $object, string $key): bool
    {
        return $object->entity->indexes->contains(fn(FetchIndexDescription $index): bool => $index->isUnique && $index->elements->contains(fn(FetchIndexElementDescription $element): bool => $element->property->name === $key));
    }

    /**
     * @param ManagedObject $object
     * @param string $key
     * @param mixed $value
     * @param ArrayClass<string> $attributeKeys
     * @return FetchRequest<Dictionary<mixed>>
     */
    private function constraintFetchRequestFor(ManagedObject $object, string $key, mixed $value, ArrayClass $attributeKeys
    ): FetchRequest
    {
        /** @var FetchRequest<Dictionary<mixed>> $fetchRequest */
        $fetchRequest = $object::fetchRequest();
        $fetchRequest->predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([new ComparisonPredicate(Expression::expressionForKeyPath($key), Expression::expressionForConstantValue($value), match ($object->entity->attributesByName[$key]?->type) {
            AttributeType::string => PredicateOperatorType::like,
            default => PredicateOperatorType::equalTo,
        }
        ), new ComparisonPredicate(Expression::expressionForKeyPath(ManagedObjectObjectIDKey), Expression::expressionForConstantValue($object->objectID->referenceObject), PredicateOperatorType::notEqualTo)]));
        $fetchRequest->propertiesToFetch = $attributeKeys;
        $fetchRequest->resultType = FetchRequestResultType::dictionaryResultType;
        if ($store = $object->objectID->persistentStore) {
            $fetchRequest->affectedStores = new ArrayClass([$store]);
        }
        return $fetchRequest;
    }

    /**
     * @param ManagedObject $object
     * @param string $key
     * @param mixed $value
     * @param ArrayClass<string> $attributeKeys
     * @param Dictionary<mixed> $baselineSnapshot
     * @return ConstraintConflict|null
     * @throws Exception
     */
    private function findConstraintConflict(ManagedObject $object, string $key, mixed $value, ArrayClass $attributeKeys, Dictionary $baselineSnapshot): ?ConstraintConflict
    {
        if (!$this->isUniqueIndexedAttribute($object, $key)) {
            return null;
        }
        $context = $object->managedObjectContext;
        $fetchRequest = $this->constraintFetchRequestFor($object, $key, $value, $attributeKeys);
        $databaseSnapshots = $context->fetch($fetchRequest);
        if ($databaseSnapshot = $databaseSnapshots->first) {
            $constraint = new ArrayClass([$key]);
            $conflictingSnapshots = new ArrayClass([$baselineSnapshot, $databaseSnapshot]);
            $conflictingObjects = new ArrayClass([$object]);
            return new ConstraintConflict($constraint, null, $databaseSnapshot, $conflictingObjects, $conflictingSnapshots);
        }
        return null;
    }
}

