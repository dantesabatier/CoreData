<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class StoreMigrationPolicy
{
    public static int $migrationDebugLevel = 0;
    /** @var ArrayClass<Bundle>|null */
    public ?ArrayClass $resourceBundles = null;
    public ?Dictionary $destinationOptions = null;
    public ?string $destinationConfiguration = null;
    public PersistentStoreType $destinationType = PersistentStoreType::sql;
    public ?URL $destinationURL = null;
    public ?MappingModel $mappingModel = null;
    public ?ManagedObjectModel $destinationModel = null;
    public ?ManagedObjectModel $sourceModel = null;
    public ?Dictionary $sourceOptions = null;
    public ?Dictionary $sourceMetadata = null;
    public ?string $sourceConfiguration = null;
    public PersistentStoreType $sourceType = PersistentStoreType::sql;
    public ?URL $sourceURL = null;
    public ?PersistentStoreCoordinator $persistentStoreCoordinator = null;

    public function createMigrationManager(ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel): ?MigrationManager
    {
        return new MigrationManager($sourceModel, $destinationModel);
    }

    /**
     * @throws Exception
     */
    public function sourceModelForStoreAtURL(URL $storeURL, Dictionary $metadata): ?ManagedObjectModel
    {
        /** @var string $storeType */
        $storeType = $metadata[StoreTypeKey];
        /** @var class-string<PersistentStore> $persistentStoreClass */
        $persistentStoreClass = PersistentStoreCoordinator::registeredStoreTypes()[$storeType];
        return $persistentStoreClass::cachedModelForPersistentStoreWithURL($storeURL);
    }

    /**
     * @throws Exception
     */
    public function addMigratedStoreToCoordinator(PersistentStoreCoordinator $coordinator, PersistentStoreType $storeType, ?string $configuration, URL $storeURL, ?Dictionary $options = null): ?PersistentStore
    {
        return $coordinator->addPersistentStoreWithType($storeType, $configuration, $storeURL, $options);
    }

    /**
     * @throws Exception
     */
    public function migrateStoreAtURL(URL $sourceURL, URL $destinationURL, PersistentStoreType $storeType, ?Dictionary $options, MigrationManager $manager): bool
    {
        $coordinator = $this->persistentStoreCoordinator ?? fatal_error();
        $persistentStore = $coordinator->persistentStore($sourceURL) ?? fatal_error();
        $metadata = $coordinator->metadata($persistentStore);
        $sourceModel = $this->sourceModelForStoreAtURL($sourceURL, $metadata) ?? fatal_error();
        $destinationModel = $this->destinationModel ?? fatal_error();
        $mappingModel = $this->mappingModel($sourceModel, $destinationModel);
        $this->willPerformMigrationWithManager($manager);
        $ok = $manager->migrateStore($sourceURL, $storeType, $options, $mappingModel, $destinationURL, $storeType, $options);
        $this->didPerformMigrationWithManager($manager);
        return $ok;
    }

    public function didPerformMigrationWithManager(MigrationManager $manager): void
    {
    }

    public function willPerformMigrationWithManager(MigrationManager $manager): void
    {
    }

    /**
     * @throws Exception
     */
    public function mappingModel(ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel): MappingModel
    {
        if ($this->destinationOptions?->valueForKey(InferMappingModelAutomaticallyOption)) {
            return MappingModel::inferredMappingModel($sourceModel, $destinationModel);
        }
        return MappingModel::mappingModel(null, $sourceModel, $destinationModel) ?? fatal_error();
    }
}
