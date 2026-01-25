<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\UUID;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\typeof;

/** @internal */
abstract readonly class HydrationStrategy
{
    public function __construct(public PersistentStore $store, public ManagedObjectContext $context)
    {
    }

    final public function mapValues(ManagedObject $object, Dictionary $snapshot): Dictionary
    {
        $snapshot->removeValueForKey("parentID");
        $representation = clone $snapshot;
        if ($representation[SQLEntity::primaryKeyName]) {
            $representation[SQLEntity::primaryKeyName] = $this->resolveManagedObjectID($object->entity, $representation);
        }
        $this->resolveStoreSpecificAttributes($object, $representation, $snapshot);
        $entity = $object->entity;
        foreach ($snapshot as $key => $value) {
            $property = $entity->propertiesByName[$key];
            if ($property instanceof RelationshipDescription) {
                $this->processRelationship($object, $representation, $key, $value, $property);
            }
        }
        return $representation;
    }

    abstract protected function resolveStoreSpecificAttributes(ManagedObject $object, Dictionary $representation, Dictionary $snapshot): void;

    protected function resolveManagedObjectID(EntityDescription $entity, Dictionary $object): ?ManagedObjectID
    {
        $objectID = $object[SQLEntity::primaryKeyName];
        if ($objectID instanceof ManagedObjectID) {
            return $objectID;
        }
        if ($objectID instanceof Nil) {
            return null;
        }
        if (($entityName = $object[SQLEntity::entityKeyName]) && is_string($entityName) && ($entityDescription = $this->context->persistentStoreCoordinator?->managedObjectModel?->entitiesByName[$entityName])) {
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

    protected function resolveManagedObject(EntityDescription $entity, ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject
    {
        if ($object instanceof ManagedObject) {
            return $object;
        }
        $targetObject = null;
        if ($object instanceof ManagedObjectID) {
            $targetObject = $this->context->object($object);
        } elseif ($objectID = $this->resolveManagedObjectID($entity, $object)) {
            $targetObject = $this->context->object($objectID);
            $targetObject->setValuesForKeys($object);
        }
        if ($targetObject && !$targetObject->isAwakeFromFetch) {
            $targetObject->isAwakeFromFetch = true;
            $targetObject->awakeFromFetch();
        }
        return $targetObject;
    }

    protected function processRelationship(ManagedObject $contextObject, Dictionary $representation, string $key, mixed $value, RelationshipDescription $relationship): void
    {
        $destinationEntity = $relationship->destinationEntity;
        if ($value instanceof ArrayClass || $value instanceof Set) {
            if ($relationship->isToMany) {
                $representation[$key] = $value->compactMap(fn(ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject => $this->resolveManagedObject($destinationEntity, $object));
            } elseif (!$value->isEmpty) {
                fatal_error(sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $contextObject->entity->name, typeof($value), $key));
            }
        } elseif ($value instanceof ManagedObject || $value instanceof ManagedObjectID || $value instanceof Dictionary) {
            $representation[$key] = $this->resolveManagedObject($destinationEntity, $value);
        } elseif ($value instanceof Nil) {
            $representation[$key] = $value;
        } else {
            fatal_error(sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $contextObject->entity->name, typeof($value), $key));
        }
    }
}
