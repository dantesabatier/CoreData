<?php

namespace Sabatier\CoreData;

use const Sabatier\Foundation\NotFound;

/** @internal */
readonly class FaultHandler
{
    public function __construct(public PersistentStore $persistentStore)
    {
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    public function fulfillFault(ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        $context ??= $object->managedObjectContext;
        /** @var IncrementalStoreNode|AtomicStoreCacheNode|null $newValues */
        $newValues = $this->persistentStore->newValuesForObjectWithID($object->objectID, $context);
        if ($newValues === null) {
            return;
        }
        if ($newValues instanceof IncrementalStoreNode) {
            $newValues = $newValues->values;
        }
        $newValues["isInserted"] = true;
        $newValues["isFault"] = false;
        $newValues["faultingState"] = 0;
        $object->isSuppressingKVO = true;
        $object->setValuesForKeys($newValues);
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
