<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Notification;
use Sabatier\Foundation\NotificationCenter;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\ObjectProtocol;
use Sabatier\Foundation\Set;

/** @internal */
final class SQLCorrelationTableUpdateTracker extends ObjectClass
{
    private ?ObjectProtocol $observer = null;
    /** @var Set<ManagedObject>|null */
    private(set) ?Set $inserts = null;
    /** @var Set<ManagedObject>|null */
    private(set) ?Set $deletes = null;
    /** @var Set<ManagedObject>|null */
    private(set) ?Set $reorders = null;

    /** @param SQLManyToMany $relationship The correlation-table relationship to update after a context save. */
    public function __construct(public readonly SQLManyToMany $relationship)
    {
    }

    /**
     * @param ManagedObjectID $objectID
     * @param Set<ManagedObject>|null $inserts
     * @param Set<ManagedObject>|null $deletes
     * @param Set<ManagedObject>|null $reorders
     */
    public function track(ManagedObjectID $objectID, ?Set $inserts = null, ?Set $deletes = null, ?Set $reorders = null): void
    {
        $this->cancel();
        $this->observer = NotificationCenter::default()->addObserverForName(ManagedObjectContext::didSaveObjectsNotification, null, function (Notification $notification) use ($inserts, $deletes, $reorders, $objectID): void {
            $this->cancel();
            /** @var ManagedObjectContext $context */
            $context = $notification->object;
            $transform = fn(ManagedObject|ManagedObjectID $e): ManagedObject => $e instanceof ManagedObject ? $e : $context->object($e);
            $object = $context->object($objectID);
            if ($inserts) {
                $cached = $inserts->map($transform);
                $inserts = new Set([$object]);
                $inserts->formUnion($cached);
            }
            if ($deletes) {
                $cached = $deletes->map($transform);
                $deletes = new Set([$object]);
                $deletes->formUnion($cached);
            }
            if ($reorders) {
                $cached = $reorders->map($transform);
                $reorders = new Set([$object]);
                $reorders->formUnion($cached);
            }
            $this->inserts = $inserts;
            $this->deletes = $deletes;
            $this->reorders = $reorders;
            /** @var SQLCore $store */
            $store = $objectID->persistentStore;
            $connection = $store->queryGenerationTrackingConnection;
            $connection->writeCorrelationChangesFromTracker($this);
        });
    }

    public function cancel(): void
    {
        if ($this->observer === null) {
            return;
        }
        NotificationCenter::default()->removeObserver($this->observer);
        $this->observer = null;
    }
}
