<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:50
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Notification;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\human_readable_value;
use const Sabatier\Foundation\NotFound;

/**
 * A set of changes in the persistent history based on a context save or batch operation.
 */
final class PersistentHistoryTransaction extends ObjectClass
{
    /** @var EntityDescription|null The entity description of the persistent history transaction entity. The entity description of {@see PersistentHistoryTransaction} lists the properties of the persistent history change. This can be useful for filtering your request.The entity description of the persistent history transaction entity. The entity description of {@see PersistentHistoryTransaction} lists the properties of the persistent history change. This can be useful for filtering your request. */
    public static ?EntityDescription $entityDescription = null;
    /** @var string|null A granular description of the context that made the persistent history change, if available. This property has a value if the managed object context sets a $transactionAuthor before the save. */
    private(set) ?string $author = null;
    /** @var string The originating bundle's identifier. */
    private(set) string $bundleID = UnknownName;
    /** @var ArrayClass<PersistentHistoryChange>|null The array of persistent history changes. */
    private(set) ?ArrayClass $changes = null;
    /** @var string|null The originating context's name. */
    private(set) ?string $contextName = null;
    /** @var string The originating process's identifier. */
    private(set) string $processID = UnknownName;
    /** @var string The originating stores identifier. */
    private(set) string $storeID = UnknownName;
    /** @var Date The date of the persistent history change. */
    private(set) Date $timestamp {
        get => $this->timestamp ??= new Date();
    }
    /** @var PersistentHistoryToken The token that represents this transaction in the persistent history. */
    private(set) PersistentHistoryToken $token {
        get => $this->token ??= new PersistentHistoryToken(new Dictionary([$this->storeID => new Number($this->transactionNumber)]));
    }
    /** @var int The transaction's numeric identifier. */
    private(set) int $transactionNumber = NotFound;
    #[Override]
    public string $description {
        get => sprintf("<%s: %s %s %s %s %s %s>", $this->class, $this->transactionNumber, $this->timestamp->description, $this->bundleID, human_readable_value($this->author), human_readable_value($this->contextName), human_readable_value($this->changes));
    }

    public function __construct(Dictionary $dictionary)
    {
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
     * Gets a notification for use in merging the transaction's changes into a managed object context.
     *
     * To merge the relevant changes into your view context, first get a notification by calling objectIDNotification() on the transaction. Then, pass the notification to {@see ManagedObjectContext::mergeChanges()}.
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
        return $context->persistentStoreCoordinator?->managedObjectModel?->entitiesByName["PersistentHistoryTransaction"];
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        return new Dictionary(["transactionID" => $this->transactionNumber, "author" => $this->author, "bundleID" => $this->bundleID, "contextName" => $this->contextName, "processID", $this->processID, "storeID" => $this->storeID, "changes" => $this->changes?->map(fn(PersistentHistoryChange $change): Dictionary => $change->jsonSerialize())]);
    }
}
