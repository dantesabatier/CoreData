<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:46
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\Value;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\human_readable_value;
use const Sabatier\Foundation\NotFound;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/**
 * A change representing the insertion, update, or deletion of a managed object in the persistent store.
 */
final class PersistentHistoryChange extends ObjectClass
{
    /** @var EntityDescription|null The entity description of the persistent history change entity. The entity description of a {@see PersistentHistoryChange}, includes its properties, which can be useful for filtering your persistent history change request. */
    public static ?EntityDescription $entityDescription = null;
    /** @var int The change's numeric identifier */
    private(set) int $changeID = NotFound;
    /** @var PersistentHistoryChangeType The type of change to the managed object in the persistent store. */
    private(set) PersistentHistoryChangeType $changeType = PersistentHistoryChangeType::insert;
    /** @var ManagedObjectID The identifier of the managed object that changed. */
    private(set) ManagedObjectID $changedObjectID;
    /** @var Dictionary<mixed>|null A dictionary of attributes marked for preservation after deletion, and their values when deleted. This value is expected on changes of type {@see PersistentHistoryChangeType::delete}. */
    private(set) ?Dictionary $tombstone = null;
    /** @var PersistentHistoryTransaction|null The persistent history transaction containing this change. */
    private(set) ?PersistentHistoryTransaction $transaction = null;
    /** @var Set<PropertyDescription>|null The set of properties that were updated on the managed object. This value is expected on changes of type {@see PersistentHistoryChangeType::update}. */
    private(set) ?Set $updatedProperties = null;
    #[Override]
    public string $description {
        get => sprintf("<%s: %s %s %s %s %s>", $this->class, $this->changeID, human_readable_value($this->changedObjectID), $this->changeType->name, human_readable_value($this->tombstone), human_readable_value($this->updatedProperties));
    }

    public function __construct(Dictionary $dictionary, ManagedObjectID $changedObjectID)
    {
        $this->changedObjectID = $changedObjectID;
        /** @var ValueTransformer $valueTransformer */
        $valueTransformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName);
        foreach ($dictionary as $key => $value) {
            if ($value instanceof Value) {
                $value = $value->value;
            }
            if ($key === "changeType") {
                $value = PersistentHistoryChangeType::from((int)$value);
            } elseif ($key === "tombstone") {
                $value = $valueTransformer->reverseTransformedValue($value);
            } elseif ($key === "updatedProperties") {
                /** @var Set<string>|null $updatedProperties */
                $updatedProperties = $valueTransformer->reverseTransformedValue($value);
                if ($updatedProperties) {
                    $value = $updatedProperties->compactMap(fn(string $name): ?PropertyDescription => $changedObjectID->entity->propertiesByName[$name]);
                }
            }
            $this->$key = $value;
        }
    }

    /**
     * A fetch request that has the persistent history change as the entity.
     * @return FetchRequest<PersistentHistoryChange>|null
     */
    public static function fetchRequest(): ?FetchRequest
    {
        if ($entity = self::$entityDescription) {
            /** @var FetchRequest<PersistentHistoryChange> $fetchRequest */
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $entity;
            return $fetchRequest;
        }
        return null;
    }

    /**
     * Requests an entity description using the provided context for the managed object type affected by the transaction.
     * @param ManagedObjectContext $context The managed object context for this request.
     * @return EntityDescription|null The entity description ({@see EntityDescription}) of the persistent history transaction entity.
     */
    public static function entityDescription(ManagedObjectContext $context): ?EntityDescription
    {
        return $context->persistentStoreCoordinator?->managedObjectModel?->entitiesByName["PersistentHistoryChange"];
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        return new Dictionary(["changeID" => $this->changeID, "changeType" => $this->changeType]);
    }
}
