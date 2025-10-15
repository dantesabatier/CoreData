<?php

namespace Sabatier\CoreData;

/**
 * @var string Key to ignore the built-in versioning provided by Core Data.
 * If true, Core Data will not compare the version hashes between the managed object model in the coordinator and the metadata for the loaded store. (It will, however, continue to update the version hash information in the metadata.)
 */
const IgnorePersistentStoreVersioningOption = "IgnorePersistentStoreVersioningOption";

/**
 * @var string Key to automatically attempt to migrate versioned stores.
 * The corresponding value is a bool. If is true and if the version hash information for the added store is determined to be incompatible with the model for the coordinator, Core Data will attempt to locate the source and mapping models in the application bundles and perform a migration.
 */
const MigratePersistentStoresAutomaticallyOption = "MigratePersistentStoresAutomaticallyOption";

/**
 * @var string Key to attempt to create the mapping model automatically. The corresponding value is a bool. If it is true and the value of the MigratePersistentStoresAutomaticallyOption is true, the coordinator will attempt to infer a mapping model if none can be found.
 */
const InferMappingModelAutomaticallyOption = "InferMappingModelAutomaticallyOption";

/** @var string The key for specifying your staged migration manager. */
const PersistentStoreStagedMigrationManagerOptionKey = "PersistentStoreStagedMigrationManagerOptionKey";

/** @var string The key for enabling deferred lightweight migrations. */
const PersistentStoreDeferredLightweightMigrationOptionKey = "PersistentStoreDeferredLightweightMigrationOptionKey";
