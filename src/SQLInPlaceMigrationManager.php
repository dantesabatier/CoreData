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
            return parent::migrateStore($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions);
        }
        /** @var PersistentStoreCoordinator $persistentStoreCoordinator */
        $persistentStoreCoordinator = $this->destinationContext->persistentStoreCoordinator;
        $destinationStore = $persistentStoreCoordinator->persistentStore($sourceURL);
        if (!$destinationStore instanceof SQLCore) {
            return parent::migrateStore($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions);
        }
        $model = new SQLModel($this->destinationModel, $destinationStore->configurationName);
        $migrator = new SQLStoreMigrator($destinationStore, $model, $mappingModel);
        $migrator->perform();
        $ok = parent::migrateStore($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions);
        $migrator->disconnect();
        return $ok;
    }
}
