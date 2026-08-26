<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\CompareOptions;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use function Sabatier\Foundation\is_equal;
use function Sabatier\Foundation\string_is_equal;

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
     * Enforces the entity's uniqueness constraints before the object is committed.
     *
     * Unlike optimistic-locking conflict detection ({@see detectConflicts()}), this runs for freshly
     * inserted objects too: those carry no {@see ManagedObject::$originalSnapshot} (it is populated only
     * when a row is read back from the store), so gating on the snapshot here would skip every insert and
     * let duplicate values slip past the atomic (XML/binary) stores, which — unlike the SQL backend — have
     * no DDL-level unique index to fall back on. Each unique-indexed attribute is checked against both the
     * rows already in the store and the other objects pending in the same save.
     * @throws Exception
     */
    public function detectConstraintConflicts(ManagedObject $object): void
    {
        $uniqueAttributeNames = $this->uniqueIndexedAttributeNames($object);
        if ($uniqueAttributeNames->isEmpty) {
            return;
        }
        $baselineSnapshot = $this->baselineSnapshotFor($object);
        $snapshotKeys = $baselineSnapshot->keys;
        $conflicts = $uniqueAttributeNames->compactMap(fn(string $key): ?ConstraintConflict => $this->findConstraintConflict($object, $key, $object->valueForKey($key), $snapshotKeys, $baselineSnapshot));
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

    /**
     * The names of the object's attributes that participate in a unique index or uniqueness constraint.
     *
     * Consults both {@see EntityDescription::$indexes} (unique fetch indexes) and
     * {@see EntityDescription::$uniquenessConstraints}: the constraints are only folded into the entity's
     * index set when the model explicitly assigns $indexes, so reading the indexes alone misses a model
     * that declares uniqueness through $uniquenessConstraints only. Only attributes are returned; a
     * constraint element that is a relationship is left to the store to enforce.
     * @param ManagedObject $object
     * @return ArrayClass<string>
     */
    private function uniqueIndexedAttributeNames(ManagedObject $object): ArrayClass
    {
        $entity = $object->entity;
        /** @var ArrayClass<string> $names */
        $names = new ArrayClass();
        foreach ($entity->indexes as $index) {
            if (!$index->isUnique) {
                continue;
            }
            foreach ($index->elements as $element) {
                $this->appendUniqueAttributeName($names, $entity, $element->property->name);
            }
        }
        foreach ($entity->uniquenessConstraints as $constraint) {
            /** @var AttributeDescription|string $element */
            foreach ($constraint as $element) {
                $this->appendUniqueAttributeName($names, $entity, $element instanceof AttributeDescription ? $element->name : $element);
            }
        }
        return $names;
    }

    /**
     * Appends $name to $names if it is an attribute of $entity and not already present.
     * @param ArrayClass<string> $names
     */
    private function appendUniqueAttributeName(ArrayClass $names, EntityDescription $entity, string $name): void
    {
        if (!$names->containsElement($name) && $entity->attributesByName[$name] instanceof AttributeDescription) {
            $names->append($name);
        }
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
        $context = $object->managedObjectContext;
        $constraint = new ArrayClass([$key]);
        $fetchRequest = $this->constraintFetchRequestFor($object, $key, $value, $attributeKeys);
        $databaseSnapshots = $context->fetch($fetchRequest);
        if ($databaseSnapshot = $databaseSnapshots->first) {
            $conflictingSnapshots = new ArrayClass([$baselineSnapshot, $databaseSnapshot]);
            $conflictingObjects = new ArrayClass([$object]);
            return new ConstraintConflict($constraint, null, $databaseSnapshot, $conflictingObjects, $conflictingSnapshots);
        }
        // The store fetch cannot see the other objects pending in the same save: none of them has been written to the store yet, so two brand-new objects that share a unique value would both pass a store-only check. Compare against the pending peers held in the context as well.
        if ($peer = $this->conflictingPendingPeer($object, $key, $value)) {
            $peerSnapshot = $peer->dictionaryWithValues($attributeKeys);
            $conflictingSnapshots = new ArrayClass([$baselineSnapshot, $peerSnapshot]);
            $conflictingObjects = new ArrayClass([$object, $peer]);
            return new ConstraintConflict($constraint, $peer, $peerSnapshot, $conflictingObjects, $conflictingSnapshots);
        }
        return null;
    }

    /**
     * The first other object pending in the same save that already carries $value for $key, or null.
     *
     * Consulting the context's registered objects covers the in-memory side of the save that the store
     * fetch in {@see findConstraintConflict()} cannot: sibling inserts (and updates) are not persisted
     * until after validation, so this is the only place a duplicate between two uncommitted objects is
     * caught. Deleted peers are ignored — they are on their way out and free their value.
     * @throws Exception
     */
    private function conflictingPendingPeer(ManagedObject $object, string $key, mixed $value): ?ManagedObject
    {
        return $object->managedObjectContext->registeredObjects->first(fn(ManagedObject $peer): bool => $peer !== $object && !$peer->isDeleted && $peer->entity === $object->entity && $this->valuesCollide($object->entity->attributesByName[$key]?->type, $value, $peer->valueForKey($key)));
    }

    private function valuesCollide(?AttributeType $type, mixed $value, mixed $otherValue): bool
    {
        if ($value === null || $otherValue === null) {
            return false;
        }
        // Mirror the store predicate in constraintFetchRequestFor(): string attributes compare with LIKE, which is case-insensitive, everything else compares by equality.
        return $type === AttributeType::string ? string_is_equal((string)$value, (string)$otherValue, CompareOptions::caseInsensitive) : is_equal($value, $otherValue);
    }
}

