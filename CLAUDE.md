# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Sabatier CoreData** is an Object Graph and Persistence framework for PHP 8.5+, inspired by Apple's Core Data. It manages complex in-memory object graphs and persists them to pluggable backends (MariaDB, XML, Binary) without requiring raw SQL.

The sibling package `sabatier/foundation` (at `../Foundation`) must be present; it provides collections, KVO, notifications, and the Psalm plugin.

## Commands

```powershell
# Install dependencies
composer install

# QA tools are installed globally with Composer. Ensure Composer's global bin
# directory is on PATH, or invoke the executables from that directory.

# Static analysis — level 3 with Foundation plugin
& "$env:APPDATA\Composer\vendor\bin\psalm.bat" --config=psalm.xml

# Automated refactoring to modern PHP (readonly, property promotion, etc.)
# Uses PHPStan as its inference engine, bundled in its own package — there is no phpstan.neon
& "$env:APPDATA\Composer\vendor\bin\rector.bat" process

# Unit tests
& "$env:APPDATA\Composer\vendor\bin\phpunit.bat"

# A single suite or test
& "$env:APPDATA\Composer\vendor\bin\phpunit.bat" --filter ManagedObjectContextTest
```

Tests live in `tests/` as PHPUnit `TestCase` classes (namespace `Sabatier\CoreData\Tests`), configured by `phpunit.xml` (bootstrap `vendor/autoload.php`, warnings and notices fail the run). Persistence tests run against an `XMLObjectStore` on a per-test temp file — `MemoryObjectStore` has no `load()` implementation and cannot be added to a coordinator. Entities under test need a real `ManagedObject` subclass registered via `managedObjectClassName`; fetch through `MySubclass::fetchRequest()` (a bare `new FetchRequest("Entity")` resolves its context from the operation queue and dies in tests). When fixing a bug, add a regression test to the matching suite.

## Architecture

All 200+ classes live in `src/` under the single namespace `Sabatier\CoreData`. The following files are always preloaded via Composer's `files` autoload (they define global constants):

- `CoreDataConstants.php`, `CoreDataConstantsInternal.php`, `StoreOptions.php`, `PersistentStoreTypes.php`, `MigrationOptions.php`, `ValidationErrorCodes.php`, `VersionKeys.php`, `UserInfoKeysForStoreChangeNotifications.php`

### Four Layers

**1. Object Graph Layer** — in-memory representation and change tracking
- `ManagedObject` — base class for all entities; tracks `changedValues`, `isInserted/Updated/Deleted`
- `ManagedObjectContext` — the "scratchpad"; all mutations go through it
- `ManagedObjectID` — unique identifier (temporary until first save, then persistent)

**2. Coordination Layer** — mediates between contexts and stores
- `PersistentStoreCoordinator` — central hub; routes requests between contexts and stores
- `ManagedObjectModel` — runtime schema built from `EntityDescription` instances
- `EntityDescription` — one entity's schema (name, attributes, relationships, fetch constraints)
- `PropertyDescription` (abstract) — base for all property metadata:
  - `AttributeDescription` — scalar values (`AttributeType` enum: String, Integer, Double, Date, UUID, URL, Decimal, …)
  - `RelationshipDescription` — references to other entities; carries delete rule and inverse
  - `FetchedPropertyDescription` — computed relationship via predicate
  - `DerivedAttributeDescription` / `ExpressionDescription` / `CompositeAttributeDescription` — derived/computed values

**3. Persistence Layer** — pluggable storage backends
- `PersistentStore` (abstract) — protocol base; manages snapshots, row caching, faulting
  - `IncrementalStore` — chunk-loading stores (databases); `SQLCore` is the concrete SQL implementation
  - `AtomicStore` — whole-file stores: `XMLObjectStore`, `BinaryObjectStore`
  - `MappedObjectStore` — external-mapping stores: `MemoryObjectStore`
- `PersistentStoreDescription` — configuration (type, URL, migration options, metadata)
- `PersistentContainer` — factory for the entire stack; returns a ready `ManagedObjectContext`

**4. Performance Layer** — lazy loading and caching
- `FaultHandler` — fires faults (lazy-loads objects on property access)
- `BatchFaultingArray` — materializes faulted objects in configurable batches
- `FaultingArray` / `FaultingSet` — collection types with built-in fault support
- `RowCache` (abstract) + `DefaultRowCache`, `APCuRowCache`, `RedisRowCache`, `MemcachedRowCache`, `NullRowCache`

### Request / Response Pattern

All operations use typed request objects (all extend `PersistentStoreRequest`):

| Request                                                            | Purpose                                                                |
|--------------------------------------------------------------------|------------------------------------------------------------------------|
| `FetchRequest<T>`                                                  | Query objects; result type controlled by `FetchRequestResultType` enum |
| `SaveChangesRequest`                                               | Persist tracked changes                                                |
| `BatchInsertRequest` / `BatchUpdateRequest` / `BatchDeleteRequest` | Bulk operations bypassing the object graph                             |
| `AsynchronousFetchRequest`                                         | Async fetch with progress/completion callbacks                         |
| `PersistentHistoryChangeRequest`                                   | Query the persistent history log                                       |

Batch operations and history queries return typed result objects extending `PersistentStoreResult`.

### MariaDB Backend

`SQLCore` (internal) backs the MariaDB store with ~50 classes covering query generation (`SQLGenerator`, `SQLStatement`, `SQLFormatter`), per-operation contexts (`SQLFetchRequestContext`, `SQLSaveChangesRequestContext`, `SQLBatchInsertRequestContext`, …), schema mapping (`SQLModel`, `SQLEntity`, `SQLSchema`), and index types (`SQLIndex`, `SQLRTreeIndex`, `SQLBinaryIndex`).

### Conflict Resolution

`MergeStrategy` interface with four strategies (all implement it):
- `ObjectTrumpStrategy` — in-memory changes win
- `StoreTrumpStrategy` — store values win
- `OverwriteStrategy` — last writer wins
- `RollbackStrategy` — discard in-memory changes

### Migration

`MigrationManager` runs a `MappingModel` over three passes — create the destination instances, relate them, validate them — delegating each to an `EntityMigrationPolicy` (one instance per entity mapping, reused across the passes). There is only ever this one engine; what differs is where the mapping model comes from:

- **Lightweight** — no mapping model exists, so `MappingModelBuilder` infers one by comparing the store's cached model with the new one. `canTransformAttributeType()` accepts identity conversions and conversion from an integer, decimal, float, double or boolean to an integer, decimal, float, double or string. Numeric narrowing is accepted even though values may be lost; conversion to boolean and date-to-string are not inferred. Anything else raises `InferredMappingModelException`.
- **Custom** — a mapping model already exists, so nothing is inferred. `MappingModel::mappingModel()` locates it in a bundle by version information rather than by file name: an `EntityMapping` records the version hashes of the entities it maps, and those identify the model pair it applies to. Mapping model files carry the extension in `MappingModelFileExtension`.

An `EntityMapping` whose `mappingType` is `customEntityMappingType` names an `EntityMigrationPolicy` subclass in `entityMigrationPolicyClassName`; that policy produces the destination values the framework cannot derive on its own. Its schema is still reconciled from the destination model, exactly as a transformation is.

`MigrationManager::canMigrateWithMappingModel()` and `performSanityCheck()` run before either store is opened: the first rejects a mapping model that is not structurally usable, the second one whose recorded version hashes disagree with the models being migrated. Both are permissive about what a hand-authored mapping model may leave out.

Staged migrations pass through the same engine. Stages extend `MigrationStage`:
- `LightweightMigrationStage` — inferred mapping
- `CustomMigrationStage` — source and destination `ManagedObjectModelReference` objects plus pre/post migration handlers; an explicit mapping model is discovered by the referenced models' version hashes

## Key Design Conventions

- **Faulting**: objects are shells until a property is accessed; never bypass `ManagedObjectContext` to pre-populate values.
- **Property hooks**: PHP 8.4 property hooks are used extensively for computed/coerced values — prefer hooks to explicit getters/setters.
- **Readonly**: classes and properties are `readonly` wherever mutation should only happen through the framework.
- **Enums over constants**: use the typed enums (`AttributeType`, `DeleteRule`, `FetchRequestResultType`, `PersistentStoreType`, …) rather than raw strings or legacy constants.
- **Notifications**: side effects on save, context change, and store change are delivered through `NotificationCenter` (from Foundation) using the constants in `CoreDataConstants.php`.
- **No public SQL**: consumer code never writes SQL; all queries are generated by the framework from `FetchRequest` predicates and sort descriptors.
- **Code style**: PSR-12, double-quoted strings, short array syntax, one-line property docblocks (`/** @var Type Description */`). There is no formatter — match the surrounding code.
- **Readonly and hooks do not mix**: PHP forbids a hooked property in a `readonly` class, so a class needing a computed property drops the class-level `readonly` and marks each promoted property `readonly` instead.
- **Give an object something to save.** Every agent that has worked on this repository has eventually written `new Entity($context)` and saved it without assigning a single attribute. The save succeeds — required attributes fall back to their type default (`040355c`, deliberate) — so the mistake is quiet, and a record whose only content is defaults carries no information. Always set an attribute, including in test fixtures and reproduction scripts. See [Give an object something to save](docs/defining-a-model.md#give-an-object-something-to-save).
- **`declare(strict_types=1)` is not repository-wide, and that is deliberate.** Foundation and Service declare it in every file; here 80 of the 202 files in `src/` deliberately do not. The store boundary marshals attribute values whose PHP type is decided at runtime by the model's `AttributeType`, not at compile time: `ManagedObject::coercedValue()` casts a `mixed` through `(string)`, `(int)` or `(float)` according to the attribute, and `XMLObjectStore` hands those values to `DOMDocument::createElement()`, which demands `string`. Adding the declaration to all 80 files turns 129 of the 438 tests into `TypeError`s. The files that *are* pure — the enums, the request/result value objects, the leaf types — do declare it, and a new file of that kind should. Do not run a blanket sweep.
