<?php

/**
 * @var string Key to represent the version hash information for the model used to create the store.
 * This key is used in the metadata for a persistent store.
 */
const StoreModelVersionHashesKey = 'StoreModelVersionHashes';

/**
 * @var string Key to represent the version identifiers for the model used to create the store.
 * If you add your own annotations to a model's version identifier (see {@see ManagedObjectModel::$versionIdentifiers}), they are stored in the persistent store's metadata. You can use this key to retrieve the identifiers from the metadata dictionaries available from PersistentStore ({@see PersistentStore::$metadata}) and PersistentStoreCoordinator ({@see PersistentStoreCoordinator::metadata()}) and related methods). The corresponding value is a Foundation collection (an ArrayClass or Set object).
 */
const StoreModelVersionIdentifiersKey = 'StoreModelVersionIdentifiers';

/**
 * @var string Key to represent the earliest version of the operation system that the persistent store supports.
 * The corresponding value is a Number object that takes the form of the constants defined by the availability macros defined in /usr/include/AvailabilityMacros.h; for example 1040 represents OS X version 10.4.0.
 * Backward compatibility may preclude some features.
 */
const PersistentStoreOSCompatibility = 'PersistentStoreOSCompatibility';
