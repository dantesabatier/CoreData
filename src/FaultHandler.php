<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final readonly class FaultHandler
{
    public function __construct(public PersistentStore $persistentStore)
    {
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    public function fulfillFault(ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        if (!$object->isFault) {
            return;
        }
        $context ??= $object->managedObjectContext;
        /** @var IncrementalStoreNode|AtomicStoreCacheNode|null $node */
        $node = $this->persistentStore->newValuesForObjectWithID($object->objectID, $context);
        if ($node === null) {
            return;
        }
        /** @var Dictionary<mixed> $snapshot */
        $snapshot = $node instanceof AtomicStoreCacheNode ? $node->propertyCache : $node->values;
        $object->isFault = false;
        $object->isSuppressingChangeNotifications = true;
        $object->isSuppressingKVO = true;
        $object->updateFromRefreshSnapshot($snapshot);
        $object->isSuppressingKVO = false;
        $object->awakeFromSnapshotEvents(SnapshotEventType::refresh);
        $object->isSuppressingChangeNotifications = false;
    }

    public function turnObjectIntoFault(ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        if ($object->isFault) {
            return;
        }
        $context ??= $object->managedObjectContext;
        $context->persistentStoreCoordinator?->persistentStoreForObject($object)?->rowCache?->deleteSnapshot($object->objectID);
        $object->isSuppressingChangeNotifications = true;
        $object->isSuppressingKVO = true;
        $object->willTurnIntoFault();
        $committedValues = $object->committedValues(null);
        $properties = $object->persistentProperties;
        foreach ($properties as $property) {
            $key = $property->name;
            $committedValue = $committedValues[$key];
            if ($property instanceof AttributeDescription) {
                $object->setPrimitiveValueForKey($committedValue, $key);
            } elseif (!$committedValue && $property instanceof FetchedPropertyDescription || $property instanceof RelationshipDescription) {
                if (($value = $object->primitiveValueForKey($key)) && ($value instanceof FaultingSet || $value instanceof FaultingArray)) {
                    $value->turnIntoFault();
                }
            }
        }
        $object->isFault = true;
        $object->faultingState = ManagedObjectFaultingStateUnstable;
        $object->didTurnIntoFault();
        $object->isSuppressingKVO = false;
        $object->isSuppressingChangeNotifications = false;
    }
}
