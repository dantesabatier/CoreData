<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\UUID;

final readonly class ManagedObjectIDResolver
{
    public function __construct(private PersistentStore $store, private ManagedObjectContext $context)
    {
    }

    public function resolve(EntityDescription $entity, Dictionary $object): ?ManagedObjectID
    {
        $objectID = $object[ManagedObjectObjectIDKey];
        if ($objectID instanceof ManagedObjectID) {
            return $objectID;
        }
        if ($objectID instanceof Nil) {
            return null;
        }
        if (($entityName = $object[ManagedObjectEntityNameKey]) && is_string($entityName) && ($entityDescription = $this->context->persistentStoreCoordinator?->managedObjectModel?->entitiesByName[$entityName])) {
            $entity = $entityDescription;
        }
        if ($objectID) {
            return $this->store->objectID($entity, $objectID);
        }
        if (!$object->isEmpty) {
            return $this->store->objectID($entity, new UUID()->uuidString);
        }
        return null;
    }
}
