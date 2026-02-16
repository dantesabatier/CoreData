<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final readonly class ManagedObjectResolver
{
    public function __construct(private ManagedObjectContext $context, private ManagedObjectIDResolver $idResolver)
    {
    }

    /**
     * @param EntityDescription $entity
     * @param ManagedObject|ManagedObjectID|Dictionary<mixed> $object
     * @return ManagedObject|null
     */
    public function resolve(EntityDescription $entity, ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject
    {
        if ($object instanceof ManagedObject) {
            return $object;
        }
        $targetObject = null;
        if ($object instanceof ManagedObjectID) {
            $targetObject = $this->context->object($object);
            $targetObject->isSuppressingChangeNotifications = $targetObject->isStable;
        } elseif ($objectID = $this->idResolver->resolve($entity, $object)) {
            $isStable = $object[ManagedObjectFaultingStateKey] === ManagedObjectFaultingStateStable;
            $targetObject = $this->context->object($objectID);
            $targetObject->isSuppressingChangeNotifications = $isStable;
            $targetObject->isSuppressingKVO = $isStable;
            $targetObject->updateFromSnapshot($object);
            $targetObject->isSuppressingKVO = false;
        }
        if ($targetObject instanceof ManagedObject) {
            if ($targetObject->isStable && !$targetObject->isAwakeFromFetch) {
                $targetObject->isAwakeFromFetch = true;
                $targetObject->awakeFromFetch();
            }
            $targetObject->isSuppressingChangeNotifications = false;
        }
        return $targetObject;
    }
}
