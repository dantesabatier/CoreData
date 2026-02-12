<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\Dictionary;

/** @internal */
abstract class SnapshotMapper
{
    protected StoreMetadataPruner $metadataPruner {
        get => $this->metadataPruner ??= new StoreMetadataPruner();
    }
    protected ManagedObjectIDResolver $idResolver {
        get => $this->idResolver ??= new ManagedObjectIDResolver($this->store, $this->context);
    }
    protected ManagedObjectResolver $objectResolver {
        get => $this->objectResolver ??= new ManagedObjectResolver($this->context, $this->idResolver);
    }
    protected RelationshipMapper $relationshipMapper {
        get => $this->relationshipMapper ??= new RelationshipMapper($this->objectResolver);
    }
    protected StoreAttributeMapper $storeAttributeMapper {
        get => $this->storeAttributeMapper ??= new StoreAttributeMapper($this->store, $this->context);
    }

    public function __construct(protected PersistentStore $store, protected ManagedObjectContext $context)
    {
    }

    /**
     * @throws Exception
     */
    final public function map(ManagedObject $object, Dictionary $snapshot): Dictionary
    {
        $this->metadataPruner->prune($snapshot);
        $mappedValues = clone $snapshot;
        if ($mappedValues[ManagedObjectObjectIDKey]) {
            $mappedValues[ManagedObjectObjectIDKey] = $this->idResolver->resolve($object->entity, $mappedValues);
        }
        $this->storeAttributeMapper->map($object, $mappedValues, $snapshot);
        $entity = $object->entity;
        foreach ($snapshot as $key => $value) {
            $property = $entity->propertiesByName[$key];
            if ($property instanceof RelationshipDescription) {
                $this->relationshipMapper->process($object, $mappedValues, $key, $value, $property);
            }
        }
        return $mappedValues;
    }
}
