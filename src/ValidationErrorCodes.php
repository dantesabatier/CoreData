<?php

/** @var int An error code that indicates a nonspecific Core Data error. */
const CoreDataError = 134060;
/** @var int An error code that indicates a migration failure during processing of an entity migration policy. */
const EntityMigrationPolicyError = 134170;
/** @var int Error code to denote a general error encountered while importing external records. */
const ExternalRecordImportError = 134200;
/** @var int Error code to denote a problem with the creation of an inferred mapping model. */
const InferredMappingModelError = 134190;
/** @var int Error code to denote a problem with the merging of instances of a managed object. */
const ManagedObjectConstraintMergeError = 133021;
/** @var int Error code to denote a problem with the validation of a managed object. */
const ManagedObjectConstraintValidationError = 1551;
/** @var int Error code to denote an inability to acquire a lock in a managed object context. */
const ManagedObjectContextLockingError = 132000;
/** @var int Error code to denote that an object being saved has a relationship containing an object from another store. */
const ManagedObjectExternalRelationshipError = 133010;
/** @var int Error code to denote that a merge policy failed—Core Data is unable to complete merging. */
const ManagedObjectMergeError = 133020;
/** @var int An error code that indicates Core Data isn’t able to find or instantiate the referenced object model. */
const ManagedObjectModelReferenceNotFoundError = 134504;
/** @var int Error code to denote an attempt to fire a fault pointing to an object that does not exist. */
const ManagedObjectReferentialIntegrityError = 133000;
/** @var int Error code to denote a generic validation error. */
const ManagedObjectValidationError = 1550;
const SQLiteError = 134180;