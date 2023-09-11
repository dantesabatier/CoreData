<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:50
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Notification;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\human_readable_value;

/**
 * A set of changes in the persistent history based on a context save or batch operation.
 */
class PersistentHistoryTransaction extends ObjectClass
{
    /** @var EntityDescription|null The entity description of the persistent history transaction entity. The entity description of {@see PersistentHistoryTransaction} lists the properties of the persistent history change. This can be useful for filtering your request.The entity description of the persistent history transaction entity. The entity description of {@see PersistentHistoryTransaction} lists the properties of the persistent history change. This can be useful for filtering your request. */
    public static ?EntityDescription $entityDescription = null;
    /** @var string|null A granular description of the context that made the persistent history change, if available. This property has a value if the managed object context set a transactionAuthor before the save. */
    public readonly ?string $author;
    /** @var string The originating bundle's identifier. */
    public readonly string $bundleID;
    /** @var ArrayClass<PersistentHistoryChange>|null The array of persistent history changes. */
    public readonly ?ArrayClass $changes;
    /** @var string|null The originating context's name. */
    public readonly ?string $contextName;
    /** @var string The originating process's identifier. */
    public readonly string $processID;
    /** @var string The originating stores identifier. */
    public readonly string $storeID;
    /** @var Date The date of the persistent history change. */
    public readonly Date $timestamp;
    /** @var PersistentHistoryToken The token that represents this transaction in the persistent history. */
    public readonly PersistentHistoryToken $token;
    /** @var int The transaction's numeric identifier. */
    public readonly int $transactionNumber;

    public function __construct(Dictionary $dictionary)
    {
        unset($this->author);
        unset($this->bundleID);
        unset($this->changes);
        unset($this->contextName);
        unset($this->processID);
        unset($this->storeID);
        unset($this->timestamp);
        unset($this->token);
        unset($this->transactionNumber);
        foreach ($dictionary as $key => $value) {
            if ($value instanceof Value) {
                $value = $value->value;
            }
            if ($key === "transactionID") {
                $key = "transactionNumber";
            } elseif ($key === "timestamp") {
                $value = new Date(strtotime((string)$value));
            }
            $this->$key = $value;
        }
    }

    public function __get(string $name)
    {
        return $this->$name = match ($name) {
            "author", "changes", "contextName" => null,
            "bundleID", "storeID", "processID" => UnknownName,
            "timestamp" => new Date(),
            "token" => new PersistentHistoryToken(new Dictionary([$this->storeID => new Number($this->transactionNumber)])),
            "transactionNumber" => 0,
            default => $this->valueForUndefinedKey($name)
        };
    }

    private function userInfoFromChanges(): ?Dictionary
    {
        if ($changes = $this->changes) {
            /** @var ArrayClass<ManagedObjectID> $insertedObjectIDs */
            $insertedObjectIDs = new ArrayClass();
            /** @var ArrayClass<ManagedObjectID> $updatedObjectIDs */
            $updatedObjectIDs = new ArrayClass();
            /** @var ArrayClass<ManagedObjectID> $deletedObjectIDs */
            $deletedObjectIDs = new ArrayClass();
            foreach ($changes as $change) {
                if ($change->changeType === PersistentHistoryChangeType::insert) {
                    $insertedObjectIDs->append($change->changedObjectID);
                } elseif ($change->changeType === PersistentHistoryChangeType::update) {
                    $updatedObjectIDs->append($change->changedObjectID);
                } else {
                    $deletedObjectIDs->append($change->changedObjectID);
                }
            }
            return new Dictionary([InsertedObjectsKey => $insertedObjectIDs, UpdatedObjectsKey => $updatedObjectIDs, DeletedObjectsKey => $deletedObjectIDs]);
        }
        return null;
    }

    /**
     * Obtains a notification for use in merging the transaction's changes into a managed object context.
     *
     * To merge the relevant changes into your view context, first obtain a notification by calling objectIDNotification() on the transaction. Then, pass the notification to {@see ManagedObjectContext::mergeChanges()}.
     * @return Notification A ManagedObjectContextDidSaveObjectIDs notification.
     */
    public function objectIDNotification(): Notification
    {
        return new Notification(ManagedObjectContext::didSaveObjectIDsNotification, null, $this->userInfoFromChanges());
    }

    /**
     * A fetch request that has the persistent history transaction as the entity.
     * @return FetchRequest<PersistentHistoryTransaction>|null
     */
    public static function fetchRequest(): ?FetchRequest
    {
        if ($entity = self::$entityDescription) {
            /** @var FetchRequest<PersistentHistoryTransaction> $fetchRequest */
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
        return $context->persistentStoreCoordinator?->managedObjectModel?->entitiesByName[self::className()];
    }

    public function description(): string
    {
        return sprintf("<%s: %s %s %s %s %s %s>", self::class, $this->transactionNumber, $this->timestamp->description(), $this->bundleID, human_readable_value($this->author), human_readable_value($this->contextName), human_readable_value($this->changes));
    }
}
