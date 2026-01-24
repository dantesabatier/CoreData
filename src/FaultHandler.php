<?php

namespace Sabatier\CoreData;

use const Sabatier\Foundation\NotFound;

/** @internal */
final readonly class FaultHandler
{
    public function __construct(public PersistentStore $persistentStore)
    {
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    public function fulfillFault(ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        $context ??= $object->managedObjectContext;
        /** @var IncrementalStoreNode|AtomicStoreCacheNode|null $snapshot */
        $snapshot = $this->persistentStore->newValuesForObjectWithID($object->objectID, $context);
        if ($snapshot === null) {
            return;
        }
        $snapshot = $snapshot instanceof AtomicStoreCacheNode ? $snapshot->propertyCache : $snapshot->values;
        $snapshot["isInserted"] = true;
        $snapshot["isFault"] = false;
        $snapshot["faultingState"] = 0;
        $object->isSuppressingKVO = true;
        $object->updateFromRefreshSnapshot($snapshot);
        $object->isSuppressingKVO = false;
        if (!$object->isAwakeFromFetch) {
            $object->isAwakeFromFetch = true;
            $object->awakeFromFetch();
        }
    }

    public function turnObjectIntoFault(/** @noinspection PhpUnusedParameterInspection */ ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        $object->isSuppressingKVO = true;
        $object->willTurnIntoFault();
        $properties = $object->persistentProperties;
        foreach ($properties as $property) {
            if ($property instanceof AttributeDescription && !$property->preservesValueInHistoryOnDeletion) {
                $object->setValueForKey(null, $property->name);
            } elseif ($property instanceof FetchedPropertyDescription || $property instanceof RelationshipDescription) {
                if (($value = $object->primitiveValueForKey($property->name)) && ($value instanceof FaultingSet || $value instanceof FaultingArray)) {
                    $value->turnIntoFault();
                }
            }
        }
        $object->isFault = true;
        $object->faultingState = NotFound;
        $object->didTurnIntoFault();
        $object->isSuppressingKVO = false;
    }
}
