<?php

namespace Sabatier\CoreData;

//Error Domains

const CoreDataErrorDomain = "CoreDataErrorDomain";
/** @var string Domain for SQL errors. */
const SQLErrorDomain = "SQLErrorDomain";

// User Info Dictionary Keys

/** @var string The key for objects prompting an error. */
const AffectedObjectsErrorKey = "AffectedObjectsErrorKey";
/** @var string The key for stores prompting an error. */
const AffectedStoresErrorKey = "AffectedStoresErrorKey";
/** @var string If multiple validation errors occur in one operation, they are collected in an array and added with this key to the “top-level error” of the operation. */
const DetailedErrorsKey = "DetailedErrorsKey";
/** @var string A user info key to identify deleted object identifiers in notifications after saving a managed object context. */
const DeletedObjectIDsKey = "DeletedObjectIDsKey";
/** @var string A user info key to identify inserted object identifiers in notifications after saving a managed object context. */
const InsertedObjectIDsKey = "InsertedObjectIDsKey";
/** @var string A user info key to identify invalidated object identifiers in notifications after saving a managed object context. */
const InvalidatedObjectIDsKey = "InvalidatedObjectIDsKey";
/** @var string A user info key to identify the history token in persistent store remote change notifications. */
const PersistentHistoryTokenKey = "PersistentHistoryTokenKey";
/** @var string A user info key to identify the store URL in persistent store remote change notifications. */
const PersistentStoreURLKey = "PersistentStoreURLKey";
/** @var string A user info key to identify refreshed object identifiers in notifications after saving a managed object context. */
const RefreshedObjectIDsKey = "RefreshedObjectIDsKey";
/** @var string A user info key to identify updated object identifiers in notifications after saving a managed object context. */
const UpdatedObjectIDsKey = "UpdatedObjectIDsKey";

// Persistent Store Metadata Keys

/** @var string A key that indicates a persistent store posts a remote change notification for every `write` to the store, including writes by other processes. */
const PersistentStoreRemoteChangeNotificationPostOptionKey = "PersistentStoreRemoteChangeNotificationPostOption";

// Persistent Store Coordinator Constants

/** @var string The key in the metadata dictionary to identify the store type. */
const StoreTypeKey = "StoreType";
/**
 * @var string The key in the metadata dictionary to identify the store UUID.
 * The store UUID is useful to identify stores through URI representations, but it is not guaranteed to be unique.
 * The UUID generated for new stores is unique—users can freely copy files and thus the UUID stored inside, so if you track or reference stores explicitly, you need to be aware of duplicate UUIDs and potentially override the UUID when a new store is added to the list of known stores in your application.
 */
const StoreUUIDKey = "StoreUUID";
/**
 * @var string A dictionary key for enabling persistent history tracking.
 * By default, persistent history tracking is disabled.
 */
const PersistentHistoryTrackingKey = "PersistentHistoryTracking";

// Notification Names

/** @var string A notification that posts for all cross-process writes to a persistent store. The user info dictionary uses the following keys: {@see PersistentStoreURLKey} specifies the store's URL. {@see StoreUUIDKey} specifies the store's unique identifier. And {@see PersistentHistoryTokenKey} specifies the persistent history token for the transaction. */
const PersistentStoreRemoteChange = "PersistentStoreRemoteChange";
/** @var string A notification that posts immediately before the coordinator updates its collection of stores. */
const PersistentStoreCoordinatorStoresWillChange = "PersistentStoreCoordinatorStoresWillChange";
/** @var string Posted whenever persistent stores are added to or removed from a persistent store coordinator, or when store UUIDs change. */
const PersistentStoreCoordinatorStoresDidChange = "PersistentStoreCoordinatorStoresDidChange";
/** @var string A notification that posts immediately before a coordinator removes a persistent store. */
const PersistentStoreCoordinatorWillRemoveStore = "PersistentStoreCoordinatorWillRemoveStore";
/**
 * @var string A notification of changes made to managed objects associated with this context.
 * The notification is posted during processPendingChanges, after the changes have been processed, but before it is safe to call save again (if you try, you will generate an infinite loop).
 * The notification object is the managed object context. The userInfo dictionary contains the following keys: {@see InsertedObjectsKey}, {@see UpdatedObjectsKey}, and {@see DeletedObjectsKey}.
 * Note that this notification is posted only when managed objects are changed; it is not posted when managed objects are added to a context as the result of a fetch.
 */
const ManagedObjectContextObjectsDidChange = "ManagedObjectContextObjectsDidChange";
/**
 * @var string A notification that the context is about to save.
 * The notification object is the managed object context. There is no userInfo dictionary.
 */
const ManagedObjectContextWillSave = "ManagedObjectContextWillSave";
/**
 * @var string A notification that the context completed a save.
 * The notification object is the managed object context. The userInfo dictionary contains the following keys:
 * {@see InsertedObjectsKey}, {@see UpdatedObjectsKey}, and {@see DeletedObjectsKey}.
 * You can only use the managed objects in this notification on the same thread on which it was posted.
 * You can pass the notification object to mergeChangesFromContextDidSaveNotification() on another thread, however, you must not use the managed object in the user info dictionary directly on another thread.
 */
const ManagedObjectContextDidSave = "ManagedObjectContextDidSave";
/** @var string A notification that posts when the context saves changes. */
const ManagedObjectContextDidSaveObjectIDs = "ManagedObjectContextDidSaveObjectIDs";

// Managed Object Context Constants

/** @var string A key for the set of objects that were inserted into the context. */
const InsertedObjectsKey = "InsertedObjectsKey";
/** @var string A key for the set of objects that were updated. */
const UpdatedObjectsKey = "UpdatedObjectsKey";
/** @var string A key for the set of objects that were marked for deletion during the previous event. */
const DeletedObjectsKey = "DeletedObjectsKey";
/** @var string The error key for the attribute that failed to validate. */
const ValidationKeyErrorKey = "ValidationKeyErrorKey";
/** @var string The error key for the object that failed to validate. */
const ValidationObjectErrorKey = "ValidationObjectErrorKey";
/** @var string The error key for the predicate that failed to validate. */
const ValidationPredicateErrorKey = "ValidationPredicateErrorKey";
/** @var string The error key for the value that failed to validate. */
const ValidationValueErrorKey = "ValidationValueErrorKey";
const ConflictListErrorKey = "conflictList";

// Managed Object Constants

const ManagedObjectObjectIDKey = "objectID";
const ManagedObjectEntityNameKey = "entityName";
const ManagedObjectVersionKey = "version";
const ManagedObjectIsInsertedKey = "isInserted";
const ManagedObjectIsFaultKey = "isFault";
const ManagedObjectFaultingStateKey = "faultingState";
const ManagedObjectFaultingStateStable = 0;
const ManagedObjectFaultingStateUnstable = -1;
const ManagedObjectParentIDKey = "parentID";
const ManagedObjectContextKey = "managedObjectContext";

// Environment Variables

const SQLSchemaName = "SQL_SCHEMA_NAME";
const SQLSchemaHost = "SQL_SCHEMA_HOST";
const SQLSchemaCredentialUser = "SQL_SCHEMA_CREDENTIAL_USER";
const SQLSchemaCredentialPassword = "SQL_SCHEMA_CREDENTIAL_PASSWORD";

// Environment Defaults

const SQLSchemaHostDefault = "127.0.0.1";
const SQLSchemaCredentialUserDefault = "root";

// Other

const UnknownName = "Unknown";
const ManagedObjectRelationshipResultKey = "result";
const ManagedObjectQueryResultKey = "queryResult";
const ManagedObjectQueryResultGenerationKey = "queryGeneration";
const SecondsPerHourTimeInterval = 3600;
