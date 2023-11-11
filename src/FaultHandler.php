<?php

namespace Sabatier\CoreData;

use Exception;
use const Sabatier\Foundation\NotFound;

/** @internal */
readonly class FaultHandler
{
    public function __construct(public PersistentStore $persistentStore)
    {
    }

    /**
     * @throws Exception
     */
    public function fulfillFault(ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        $context ??= $object->managedObjectContext;
        $entity = $object->entity;
        /** @var IncrementalStoreNode|AtomicStoreCacheNode|null $newValues */
        $newValues = $this->persistentStore->newValuesForObjectWithID($object->objectID, $context);
        if ($newValues) {
            if ($newValues instanceof IncrementalStoreNode) {
                $newValues = $newValues->values;
            }
            $object->isSuppressingKVO = true;
            $committedValues = $object->committedValuesForKeys(null);
            foreach ($entity as $property) {
                if ($property instanceof AttributeDescription) {
                    $value = $newValues->valueForKey($property->name);
                    if ($value !== null) {
                        $object->setValueForKey($value, $property->name);
                    }
                } elseif ($property instanceof RelationshipDescription) {
                    if ($committedValues[$property->name]) {
                        $object->valueForKey($property->name);
                    }
                }
            }
            $object->faultingState = 0;
            $object->isFault = false;
            $object->isSuppressingKVO = false;
            $object->awakeFromFetch();
        }
    }

    /**
     * @throws Exception
     */
    public function turnObjectIntoFault(/** @noinspection PhpUnusedParameterInspection */ ManagedObject $object, ?ManagedObjectContext $context = null): void
    {
        $object->isSuppressingKVO = true;
        $object->willTurnIntoFault();
        $properties = $object->persistentProperties;
        foreach ($properties as $property) {
            if ($property instanceof AttributeDescription && !$property->preservesValueInHistoryOnDeletion) {
                $object->setValueForKey(null, $property->name);
            } elseif ($property instanceof FetchedPropertyDescription || $property instanceof RelationshipDescription) {
                if (($value = $object->primitiveValueForKey($property->name)) && ($value instanceof FaultingMutableSet || $value instanceof FaultingMutableArray)) {
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
