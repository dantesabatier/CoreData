<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/07/20
 * Time: 08:54
 */
namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use function Sabatier\Foundation\human_readable_value;

/**
 * An encapsulation of conflicts that occur during an attempt to save changes in a managed object context.
 *
 * A conflict can occur in two situations:
 * Between the managed object context and its in-memory cached state at the persistent store coordinator layer.
 * Between the cached state at the persistent store coordinator layer and the external store (file, database, and so forth). In this case, the merge conflict has a cached snapshot and a persisted snapshot. The source object is also provided as a convenience, but it is not directly involved in the conflict.
 * Snapshot dictionaries include values for all attributes and to-one relationships, but not to-many relationships. Relationship values are ManagedObjectID references. To-many relationships must be pulled from the persistent store as needed.
 */
final class MergeConflict extends ObjectClass
{
    /** @var Dictionary<mixed> A dictionary containing the values of the source object. */
    private(set) Dictionary $objectSnapshot {
        get => $this->objectSnapshot ??= $this->sourceObject->dictionaryWithValues($this->sourceObject->persistentProperties->keys->filter(fn(string $key): bool => !$this->sourceObject->isPropertyForKeyFault($key)));
    }
    #[Override]
    public string $description {
        get => sprintf("%s (%s) for %s (%s) with objectID %s with oldVersion = %s and newVersion = %s and old object snapshot %s", $this->class, $this->hash, $this->sourceObject::class, $this->sourceObject->hash, $this->sourceObject->objectID->description, $this->oldVersionNumber, $this->newVersionNumber, human_readable_value($this->cachedSnapshot));
    }

    /**
     * Initializes a merge conflict.
     * @param ManagedObject $sourceObject The source object for the conflict.
     * @param int $newVersionNumber The new version number for the change. A value of 0 means the object was deleted and the corresponding snapshot is null.
     * @param int $oldVersionNumber The old version number for the change.
     * @param Dictionary<mixed>|null $cachedSnapshot A dictionary containing the values of sourceObject held in the persistent store coordinator layer.
     * @param Dictionary<mixed>|null $persistedSnapshot A dictionary containing the values of sourceObject held in the persistent store.
     */
    public function __construct(public readonly ManagedObject $sourceObject, public readonly int $newVersionNumber, public readonly int $oldVersionNumber, public readonly ?Dictionary $cachedSnapshot = null, public readonly ?Dictionary $persistedSnapshot = null)
    {
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        return new Dictionary(["cachedSnapshot" => $this->cachedSnapshot, "persistedSnapshot" => $this->persistedSnapshot, "proposedSnapshot" => $this->objectSnapshot]);
    }
}
