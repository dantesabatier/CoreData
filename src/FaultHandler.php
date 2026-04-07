<?php

namespace Sabatier\CoreData;

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
        $snapshot = $node instanceof AtomicStoreCacheNode ? $node->propertyCache : $node->values;
        $object->isFault = false;
        $object->isSuppressingChangeNotifications = true;
        $object->isSuppressingKVO = true;
        $object->updateFromRefreshSnapshot($snapshot);
        $object->isSuppressingKVO = false;
        $object->awakeFromSnapshotEvents(SnapshotEventType::refresh);
        $object->isSuppressingChangeNotifications = false;
        $object->faultingState = ManagedObjectFaultingStateStable;
    }

    public function turnObjectIntoFault(/** @noinspection PhpUnusedParameterInspection */ ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        if ($object->isFault) {
            return;
        }
        $object->isSuppressingChangeNotifications = true;
        $object->isSuppressingKVO = true;
        $object->willTurnIntoFault();
        $object->isFault = true;
        $committedValues = $object->committedValues(null);
        $properties = $object->persistentProperties;
        foreach ($properties as $property) {
            $key = $property->name;
            $committedValue = $committedValues[$key];
            if ($property instanceof AttributeDescription) {
                $object->setValueForKey($committedValue, $key);
            } elseif (!$committedValue && $property instanceof FetchedPropertyDescription || $property instanceof RelationshipDescription) {
                if (($value = $object->primitiveValueForKey($key)) && ($value instanceof FaultingSet || $value instanceof FaultingArray)) {
                    $value->turnIntoFault();
                }
            }
        }
        $object->didTurnIntoFault();
        $object->faultingState = ManagedObjectFaultingStateUnstable;
        $object->isSuppressingKVO = false;
        $object->isSuppressingChangeNotifications = false;
    }
}
