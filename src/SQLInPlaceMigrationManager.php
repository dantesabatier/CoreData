<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\URL;

/** @internal */
class SQLInPlaceMigrationManager extends MigrationManager
{
    public function migrateStore(URL $sourceURL, PersistentStoreType $sourceType, ?Dictionary $sourceOptions, MappingModel $mappingModel, URL $destinationURL, PersistentStoreType $destinationType, ?Dictionary $destinationOptions): bool
    {
        if (!$this->prepare($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions)) {
            return false;
        }
        $destinationOptions?->removeValueForKey(MigratePersistentStoresAutomaticallyOption);
        /** @var PersistentStoreCoordinator $persistentStoreCoordinator */
        $persistentStoreCoordinator = $this->sourceContext->persistentStoreCoordinator;
        /** @var SQLCore $store */
        $store = $persistentStoreCoordinator->persistentStore($sourceURL);
        $model = new SQLModel($this->destinationModel, $store->configurationName);
        $migrator = new SQLStoreMigrator($store, $model, $mappingModel);
        $migrator->perform();
        $ok = parent::migrateStore($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions);
        $migrator->disconnect();
        return $ok;
    }
}
