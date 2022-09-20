<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * Class MigrationContext
 * @package Sabatier\CoreData
 * @internal
 */
class MigrationContext
{
    /** @var Dictionary<ManagedObject> */
    private Dictionary $bySourceAssociationTable;
    /** @var Dictionary<ManagedObject> */
    private Dictionary $byDestinationAssociationTable;
    /** @var Dictionary<ArrayClass<ManagedObject>> */
    private Dictionary $byMappingBySourceAssociationTable;
    /** @var Dictionary<ArrayClass<ManagedObject>> */
    private Dictionary $byMappingByDestinationAssociationTable;
    public ?EntityMapping $currentEntityMapping = null;
    public ?PropertyMapping $currentPropertyMapping = null;
    public int $currentMigrationStep = 0;

    public function __construct(public readonly MigrationManager $migrationManager)
    {
        $this->bySourceAssociationTable = new Dictionary();
        $this->byDestinationAssociationTable = new Dictionary();
        $this->byMappingBySourceAssociationTable = new Dictionary();
        $this->byMappingByDestinationAssociationTable = new Dictionary();
    }

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

    public function destinationInstances(?EntityMapping $entityMapping = null, ?ManagedObject $sourceInstance = null): ArrayClass
    {
        if ($sourceInstance) {
            if ($destinationInstance = $this->byDestinationAssociationTable[(string)$sourceInstance->objectID]) {
                return new ArrayClass([$destinationInstance]);
            }
        } elseif ($entityMapping) {
            if (($destinationEntityName = $entityMapping->destinationEntityName) && ($destinationInstances = $this->byMappingByDestinationAssociationTable[$destinationEntityName])) {
                return $destinationInstances;
            }
        }
        return new ArrayClass();
    }

    public function sourceInstances(?EntityMapping $entityMapping = null, ?ManagedObject $destinationInstance = null): ArrayClass
    {
        if ($destinationInstance) {
            if ($sourceInstance = $this->bySourceAssociationTable[(string)$destinationInstance->objectID]) {
                return new ArrayClass([$sourceInstance]);
            }
        } elseif ($entityMapping) {
            if (($sourceEntityName = $entityMapping->sourceEntityName) && ($sourceInstances = $this->byMappingBySourceAssociationTable[$sourceEntityName])) {
                return $sourceInstances;
            }
        }
        return new ArrayClass();
    }
}