<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * @internal
 */
final class MigrationContext
{
    /** @var Dictionary<ManagedObject> */
    private Dictionary $bySourceAssociationTable {
        get => $this->bySourceAssociationTable ??= new Dictionary();
    }
    /** @var Dictionary<ManagedObject> */
    private Dictionary $byDestinationAssociationTable {
        get => $this->byDestinationAssociationTable ??= new Dictionary();
    }
    /** @var Dictionary<ArrayClass<ManagedObject>> */
    private Dictionary $byMappingBySourceAssociationTable {
        get => $this->byMappingBySourceAssociationTable ??= new Dictionary();
    }
    /** @var Dictionary<ArrayClass<ManagedObject>> */
    private Dictionary $byMappingByDestinationAssociationTable {
        get => $this->byMappingByDestinationAssociationTable ??= new Dictionary();
    }
    public ?EntityMapping $currentEntityMapping = null;
    public ?PropertyMapping $currentPropertyMapping = null;
    public int $currentMigrationStep = 0;

    private function createAssociationsBySource(ManagedObject $source, ManagedObject $destination, EntityMapping $mapping): void
    {
        if ($sourceEntityName = $mapping->sourceEntityName) {
            /** @var ArrayClass<ManagedObject> $sources */
            $sources = $this->byMappingBySourceAssociationTable[$sourceEntityName] ?? new ArrayClass();
            $sources->append($source);
            $this->byMappingBySourceAssociationTable[$sourceEntityName] = $sources;
        }
        $this->bySourceAssociationTable[(string)$destination->objectID] = $source;
    }

    private function createAssociationsByDestination(ManagedObject $destination, ManagedObject $source, EntityMapping $mapping): void
    {
        if ($destinationEntityName = $mapping->destinationEntityName) {
            /** @var ArrayClass<ManagedObject> $destinations */
            $destinations = $this->byMappingByDestinationAssociationTable[$destinationEntityName] ?? new ArrayClass();
            $destinations->append($destination);
            $this->byMappingByDestinationAssociationTable[$destinationEntityName] = $destinations;
        }
        $this->byDestinationAssociationTable[(string)$source->objectID] = $destination;
    }

    public function associate(ManagedObject $sourceInstance, ManagedObject $destinationInstance, EntityMapping $entityMapping): void
    {
        $this->createAssociationsBySource($sourceInstance, $destinationInstance, $entityMapping);
        $this->createAssociationsByDestination($destinationInstance, $sourceInstance, $entityMapping);
    }

    public function clearAssociationTables(): void
    {
        $this->bySourceAssociationTable->removeAll();
        $this->byDestinationAssociationTable->removeAll();
        $this->byMappingBySourceAssociationTable->removeAll();
        $this->byMappingByDestinationAssociationTable->removeAll();
    }

    /**
     * @param EntityMapping|null $entityMapping
     * @param ManagedObject|null $sourceInstance
     * @return ArrayClass<ManagedObject>
     */
    public function destinationInstances(?EntityMapping $entityMapping = null, ?ManagedObject $sourceInstance = null): ArrayClass
    {
        if ($sourceInstance && ($destinationInstance = $this->byDestinationAssociationTable[(string)$sourceInstance->objectID])) {
            return new ArrayClass([$destinationInstance]);
        }
        if ($entityMapping && ($destinationEntityName = $entityMapping->destinationEntityName) && ($destinationInstances = $this->byMappingByDestinationAssociationTable[$destinationEntityName])) {
            return $destinationInstances;
        }
        return new ArrayClass();
    }

    /**
     * @param EntityMapping|null $entityMapping
     * @param ManagedObject|null $destinationInstance
     * @return ArrayClass<ManagedObject>
     */
    public function sourceInstances(?EntityMapping $entityMapping = null, ?ManagedObject $destinationInstance = null): ArrayClass
    {
        if ($destinationInstance && ($sourceInstance = $this->bySourceAssociationTable[(string)$destinationInstance->objectID])) {
            return new ArrayClass([$sourceInstance]);
        }
        if ($entityMapping && ($sourceEntityName = $entityMapping->sourceEntityName) && ($sourceInstances = $this->byMappingBySourceAssociationTable[$sourceEntityName])) {
            return $sourceInstances;
        }
        return new ArrayClass();
    }
}
