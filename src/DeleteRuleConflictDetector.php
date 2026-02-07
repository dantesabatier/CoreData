<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
final readonly class DeleteRuleConflictDetector
{
    public function __construct(private SnapshotProvider $snapshotProvider)
    {
    }

    /**
     * @param ManagedObject $object
     * @param Dictionary<mixed> $baselineSnapshot
     * @return ArrayClass<MergeConflict>
     */
    public function conflictsForDeletion(ManagedObject $object, Dictionary $baselineSnapshot): ArrayClass
    {
        $snapshotKeys = $baselineSnapshot->keys;
        return $object->entity->relationshipsByName->compactMap(fn(RelationshipDescription $relationship): ?MergeConflict => $this->conflictForRelationship($object, $relationship, $baselineSnapshot, $snapshotKeys));
    }

    /**
     * @param ManagedObject $object
     * @param RelationshipDescription $relationship
     * @param Dictionary<mixed> $baselineSnapshot
     * @param ArrayClass<string> $snapshotKeys
     * @return MergeConflict|null
     */
    private function conflictForRelationship(ManagedObject $object, RelationshipDescription $relationship, Dictionary $baselineSnapshot, ArrayClass $snapshotKeys): ?MergeConflict
    {
        if ($relationship->inverseRelationship->deleteRule !== DeleteRule::denyDeleteRule) {
            return null;
        }
        if ($relationship->isToMany) {
            $expression = new ExpressionDescription();
            $expression->entity = $object->entity;
            $expression->name = "computedValue";
            $expression->expression = Expression::expressionWithFormat("%K", new ArrayClass(["$relationship->name.@count"]));
            $expression->resultType = AttributeType::integer32;
            $storeSnapshot = $this->snapshotProvider->snapshotWithExpressions($object, $snapshotKeys, new ArrayClass([$expression]));
            if ($storeSnapshot && $storeSnapshot["computedValue"] > 0) {
                $storeSnapshot->removeValueForKey("computedValue");
                return new MergeConflict($object, $storeSnapshot[ManagedObjectVersionKey], $baselineSnapshot[ManagedObjectVersionKey], $baselineSnapshot, $storeSnapshot);
            }
            return null;
        }
        $storeSnapshot = $this->snapshotProvider->snapshot($object, $snapshotKeys);
        if ($storeSnapshot) {
            return new MergeConflict($object, $storeSnapshot[ManagedObjectVersionKey], $baselineSnapshot[ManagedObjectVersionKey], $baselineSnapshot, $storeSnapshot);
        }
        return null;
    }
}
