<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

final readonly class ManagedObjectResolver
{
    public function __construct(private ManagedObjectContext $context, private ManagedObjectIDResolver $idResolver)
    {
    }

    public function resolve(EntityDescription $entity, ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject
    {
        if ($object instanceof ManagedObject) {
            return $object;
        }
        $targetObject = null;
        $isFullyInitialized = false;
        if ($object instanceof ManagedObjectID) {
            $targetObject = $this->context->object($object);
            $isFullyInitialized = $targetObject->faultingState === ManagedObjectFaultingStateStable;
            $targetObject->isSuppressingChangeNotifications = $isFullyInitialized;
        } elseif ($objectID = $this->idResolver->resolve($entity, $object)) {
            $isFullyInitialized = $object[ManagedObjectFaultingStateKey] === ManagedObjectFaultingStateStable;
            $targetObject = $this->context->object($objectID);
            $targetObject->isSuppressingChangeNotifications = $isFullyInitialized;
            $targetObject->isSuppressingKVO = $isFullyInitialized;
            $targetObject->updateFromSnapshot($object);
            $targetObject->isSuppressingKVO = false;
        }
        if ($targetObject instanceof ManagedObject) {
            if (!$targetObject->isAwakeFromFetch && $isFullyInitialized) {
                $targetObject->isAwakeFromFetch = true;
                $targetObject->awakeFromFetch();
            }
            $targetObject->isSuppressingChangeNotifications = false;
        }
        return $targetObject;
    }
}
