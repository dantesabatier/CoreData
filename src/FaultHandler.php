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
        /** @var IncrementalStoreNode|AtomicStoreCacheNode $node */
        $node = $this->persistentStore->newValuesForObjectWithID($object->objectID, $context);
        /** @var Dictionary<mixed> $snapshot */
        $snapshot = $node instanceof AtomicStoreCacheNode ? $node->propertyCache : $node->values;
        $object->isFault = false;
        $object->isSuppressingChangeNotifications = true;
        $object->isSuppressingKVO = true;
        $object->updateFromRefreshSnapshot($snapshot);
        $properties = $object->persistentProperties;
        foreach ($properties as $property) {
            if ($property instanceof FetchedPropertyDescription || $property instanceof RelationshipDescription) {
                $value = $object->primitiveValueForKey($property->name);
                if ($value instanceof FaultingSet || $value instanceof FaultingArray) {
                    $value->turnIntoFault();
                }
            }
        }
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
        $object->isSuppressingChangeNotifications = true;
        $object->isSuppressingKVO = true;
        $object->willTurnIntoFault();
        $context->persistentStoreCoordinator?->persistentStoreForObject($object)?->rowCache?->deleteSnapshot($object->objectID);
        $committedValues = $object->committedValues(null);
        $properties = $object->persistentProperties;
        foreach ($properties as $property) {
            $key = $property->name;
            $committedValue = $committedValues[$key];
            if ($property instanceof AttributeDescription) {
                $object->setPrimitiveValueForKey($committedValue, $key);
            } elseif ($property instanceof FetchedPropertyDescription || $property instanceof RelationshipDescription) {
                $value = $object->primitiveValueForKey($key);
                if ($value instanceof FaultingSet || $value instanceof FaultingArray) {
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
