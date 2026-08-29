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

### Removed

- Removed migration-option placeholders that had no runtime implementation.
