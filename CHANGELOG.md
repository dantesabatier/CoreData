# Changelog

All notable changes to Sabatier CoreData are documented in this file.

The project follows [Semantic Versioning](https://semver.org/). Until the first stable release,
entries remain under **Unreleased**.

## Unreleased

### Added

- Object-graph management with change tracking, undo and inverse relationship maintenance.
- MariaDB persistence with generated queries, batched fetching and optimistic locking.
- Atomic XML and binary stores.
- Inferred, custom and staged model migrations.
- In-process, APCu, Redis and Memcached row-cache backends.
- Batch insert, update and delete requests and persistent-history queries.

### Changed

- Added public relationship-name properties for assembling models without using internal state.
- Clarified the supported MariaDB backend, required PHP extensions and migration behavior.
- Setting a fetch request's `entity` now populates its `entityName` as well, so both halves of
  the request's identity are present, however, it was built.
- The query cache key is now a digest rather than an escaped description, keeping it within the
  key-length limits of the shared cache backends.
- `FetchedResultsSectionInfo::$numberOfObjects` is derived on read instead of captured at
  construction.
- Required PHP extensions are down to those the framework uses on every path. APCu, Redis and
  Memcached are opt-in row-cache backends and moved to `suggest`.

### Fixed

- Custom migrations now persist the values their policy produces; previously the destination
  context was saved only when the migration ran through the coordinator.
- `FetchedResultsController` now tracks context changes, admits only objects of the entity it
  fetches — an unrelated entity in the same change batch used to enter the results and break
  sorting — and reports index paths for the first row of a section.
- A deserialized `ManagedObjectID` no longer re-resolves its store identifier.
- Atomic stores can now resolve a to-one relationship whose inverse is to-many. That combination
  had no branch in `AtomicStore::newValueForRelationship`, so once a fault was fulfilled — which
  re-faults every to-one — the relationship read as `null` for the rest of the session. Mutating
  any attribute on a saved object was enough to trigger it. The SQL store was never affected.
- The XML store no longer serializes an unresolved relationship fault as an empty relationship.
  Saves that only change attributes preserve stored references, while explicitly assigning an
  empty set or `null` still clears the relationship.

### Removed

- Removed migration-option placeholders that had no runtime implementation.

### Documentation

- Added `docs/` covering how to define a model, configure the MariaDB store and write migrations.
- Added `SECURITY.md` and `CONTRIBUTING.md`.
