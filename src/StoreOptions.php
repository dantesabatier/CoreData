<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

/** @var string Options key used to specify a unique string identifier for the persistent store. This identifier is used to construct ManagedObjectIDs and as a namespace for caching mechanisms. If this option is not provided, the framework generates a deterministic identifier based on the store's URL. */
const PersistentStoreIDOption = "PersistentStoreIDOption";
/** @var string A flag that indicates whether a store is treated as read-only or not. The default value is false. */
const ReadOnlyPersistentStoreOption = "ReadOnlyPersistentStoreOption";
/** @var string A flag that indicates whether an XML file should be validated with the DTD while opening. The default value is false. */
const ValidateXMLStoreOption = "ValidateXMLStoreOption";
/** @var string Options key that specifies the connection timeout for Core Data stores. The corresponding value is a number object that represents the duration in seconds that Core Data will wait while attempting to create a connection to a persistent store. If a connection is cannot be made within that timeframe, the operation is aborted and an error is returned. */
const PersistentStoreTimeoutOption = "PersistentStoreTimeoutOption";
/** @var string Options key used to specify the URL of the managed object model (.mom) associated with the store. This is useful when the store needs to be initialized or migrated using a specific version of the model located at a custom path. */
const ManagedObjectModelURLOption = "ManagedObjectModelURLOption";
/** @var string Options key that specifies the time interval, in seconds, for which cached snapshots and relationship results remain valid in the persistent store cache. If this interval is exceeded, the framework will invalidate the cached entry and fetch fresh data from the primitive store during the next access. The default value is 3600 seconds (1 hour). */
const PersistentStoreCacheStalenessIntervalOption = "PersistentStoreCacheStalenessIntervalOption";
