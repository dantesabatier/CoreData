<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:46
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\Value;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\human_readable_value;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/**
 * A change representing the insertion, update, or deletion of a managed object in the persistent store.
 */
class PersistentHistoryChange extends ObjectClass
{
    /** @var EntityDescription|null The entity description of the persistent history change entity. The entity description of a {@see PersistentHistoryChange}, includes its properties, which can be useful for filtering your persistent history change request. */
    public static ?EntityDescription $entityDescription = null;
    /** @var int The change's numeric identifier */
    public readonly int $changeID;
    /** @var PersistentHistoryChangeType The type of change to the managed object in the persistent store. */
    public readonly PersistentHistoryChangeType $changeType;
    /** @var ManagedObjectID The identifier of the managed object that changed. */
    public readonly ManagedObjectID $changedObjectID;
    /** @var Dictionary|null A dictionary of attributes marked for preservation after deletion, and their values when deleted. This value is expected on changes of type {@see PersistentHistoryChangeType::delete}. */
    public readonly ?Dictionary $tombstone;
    /** @var PersistentHistoryTransaction|null The persistent history transaction containing this change. */
    public readonly ?PersistentHistoryTransaction $transaction;
    /** @var Set<PropertyDescription>|null The set of properties that were updated on the managed object. This value is expected on changes of type {@see PersistentHistoryChangeType::update}. */
    public readonly ?Set $updatedProperties;

    public function __construct(Dictionary $dictionary, ManagedObjectID $changedObjectID)
    {
        unset($this->tombstone);
        unset($this->transaction);
        unset($this->updatedProperties);
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

    public function __get(string $name)
    {
        return $this->$name = match ($name) {
            "tombstone", "transaction", "updatedProperties" => null,
            default => $this->valueForUndefinedKey($name)
        };
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

    public function description(): string
    {
        return sprintf("<%s: %s %s %s %s %s>", self::class, $this->changeID, human_readable_value($this->changedObjectID), $this->changeType->name, human_readable_value($this->tombstone), human_readable_value($this->updatedProperties));
    }

    public function jsonSerialize(): Dictionary
    {
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = new Dictionary();
        $dictionary["changeID"] = $this->changeID;
        $dictionary["changeType"] = $this->changeType;
        $dictionary["tombstone"] = $this->tombstone;
        $dictionary["updatedProperties"] = $this->updatedProperties;
        return $dictionary;
    }
}
