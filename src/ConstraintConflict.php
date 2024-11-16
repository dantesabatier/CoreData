<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use function Sabatier\Foundation\human_readable_value;

/**
 * An encapsulation of conflicts that occur during an attempt to save a managed object.
 *
 * A constraint conflict occurs when your data model is using unique constraints and one or more managed objects are violating that constraint.
 * When this error occurs, the error instance can be interrogated to determine which instance of {@see ManagedObject} is violating the constraint and which property on the {@see ManagedObject} instance is in violation.
 */
class ConstraintConflict extends ObjectClass
{
    /** @var Dictionary The values that the conflicting objects had when the conflict was created. */
    private(set) Dictionary $constraintValues;
    public string $description {
        get => sprintf("%s %s for constraint (%s): database(%s): conflictedObjects (%s):", get_called_class(), $this->hash, $this->constraint->join(", "), human_readable_value($this->databaseObject), $this->conflictingObjects->join(", "));
    }

    /**
     * Initializes a constraint conflict.
     * @param ArrayClass<string> $constraint The constraint that has been violated.
     * @param ManagedObject|null $databaseObject The object whose database row is using constraint values.
     * @param Dictionary|null $databaseSnapshot The values currently stored in the database.
     * @param ArrayClass<ManagedObject> $conflictingObjects The managed objects that are in conflict.
     * @param ArrayClass<Dictionary> $conflictingSnapshots The original property values of objects in violation of the constraint.
     */
    public function __construct(public readonly ArrayClass $constraint, public readonly ?ManagedObject $databaseObject, public readonly ?Dictionary $databaseSnapshot, public readonly ArrayClass $conflictingObjects, public readonly ArrayClass $conflictingSnapshots)
    {
        $this->constraintValues = new Dictionary();
    }
}
