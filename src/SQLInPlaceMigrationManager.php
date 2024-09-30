<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\URL;

/** @internal */
class SQLInPlaceMigrationManager extends MigrationManager
{
    #[Override]
    public function migrateStore(URL $sourceURL, PersistentStoreType $sourceType, ?Dictionary $sourceOptions, MappingModel $mappingModel, URL $destinationURL, PersistentStoreType $destinationType, ?Dictionary $destinationOptions): bool
    {
        if (!$this->prepare($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions)) {
            return false;
        }
        /** @var PersistentStoreCoordinator $persistentStoreCoordinator */
        $persistentStoreCoordinator = $this->destinationContext->persistentStoreCoordinator;
        $store = $persistentStoreCoordinator->persistentStore($sourceURL);
        if (!$store instanceof SQLCore) {
            return parent::migrateStore($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions);
        }
        $destinationModel = new SQLModel($this->destinationModel, $store->configurationName);
        $migrator = new SQLStoreMigrator($store, $destinationModel, $mappingModel);
        $migrator->perform();
        $ok = parent::migrateStore($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions);
        $migrator->disconnect();
        return $ok;
    }
}
