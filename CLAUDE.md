# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Sabatier CoreData** is an Object Graph and Persistence framework for PHP 8.5+, inspired by Apple's Core Data. It manages complex in-memory object graphs and persists them to pluggable backends (SQL, XML, Binary, In-Memory) without requiring raw SQL.

The sibling package `sabatier/foundation` (at `../Foundation`) must be present; it provides collections, KVO, notifications, and the Psalm plugin.

## Commands

```bash
# Install dependencies
composer install

# Code style (PSR-12, short arrays, strict params)
vendor/bin/php-cs-fixer fix

# Static analysis — level 3 with Foundation plugin
vendor/bin/psalm

# Static analysis — level 2
vendor/bin/phpstan analyse

# Automated refactoring to modern PHP (readonly, property promotion, etc.)
vendor/bin/rector process
```

There is no test suite in this repository.

## Architecture

All 200+ classes live in `src/` under the single namespace `Sabatier\CoreData`. The following files are always pre-loaded via Composer's `files` autoload (they define global constants):

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

| Request | Purpose |
|---|---|
| `FetchRequest<T>` | Query objects; result type controlled by `FetchRequestResultType` enum |
| `SaveChangesRequest` | Persist tracked changes |
| `BatchInsertRequest` / `BatchUpdateRequest` / `BatchDeleteRequest` | Bulk operations bypassing the object graph |
| `AsynchronousFetchRequest` | Async fetch with progress/completion callbacks |
| `PersistentHistoryChangeRequest` | Query the persistent history log |

Batch operations and history queries return typed result objects extending `PersistentStoreResult`.

### SQL Backend

`SQLCore` (internal) backs the SQL store with ~50 classes covering query generation (`SQLGenerator`, `SQLStatement`, `SQLFormatter`), per-operation contexts (`SQLFetchRequestContext`, `SQLSaveChangesRequestContext`, `SQLBatchInsertRequestContext`, …), schema mapping (`SQLModel`, `SQLEntity`, `SQLSchema`), and index types (`SQLIndex`, `SQLRTreeIndex`, `SQLBinaryIndex`).

### Conflict Resolution

`MergeStrategy` interface with four strategies (all implement it):
- `ObjectTrumpStrategy` — in-memory changes win
- `StoreTrumpStrategy` — store values win
- `OverwriteStrategy` — last writer wins
- `RollbackStrategy` — discard in-memory changes

### Migration

`MigrationManager` orchestrates schema migrations. Stages extend `MigrationStage`:
- `LightweightMigrationStage` — inferred mapping
- `CustomMigrationStage` — explicit `MappingModel` / `EntityMigrationPolicy`

## Key Design Conventions

- **Faulting**: objects are shells until a property is accessed; never bypass `ManagedObjectContext` to pre-populate values.
- **Property hooks**: PHP 8.4 property hooks are used extensively for computed/coerced values — prefer hooks over explicit getters/setters.
- **Readonly**: classes and properties are `readonly` wherever mutation should only happen through the framework.
- **Enums over constants**: use the typed enums (`AttributeType`, `DeleteRule`, `FetchRequestResultType`, `PersistentStoreType`, …) rather than raw strings or legacy constants.
- **Notifications**: side-effects on save, context change, and store change are delivered through `NotificationCenter` (from Foundation) using the constants in `CoreDataConstants.php`.
- **No public SQL**: consumer code never writes SQL; all queries are generated by the framework from `FetchRequest` predicates and sort descriptors.
