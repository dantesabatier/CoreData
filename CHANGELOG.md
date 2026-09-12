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
- `ExtractLocalizables.php` extracts the localizations the bundle declares rather than a
  hard-coded English and Spanish pair, so adding a locale to `Info.plist` is enough for the
  extractor to pick it up.

### Fixed

- Custom migrations now persist the values their policy produces; previously the destination
  context was saved only when the migration ran through the coordinator.
- `FetchedResultsController` now tracks context changes, admits only objects of the entity it
  fetches — an unrelated entity in the same change batch used to enter the results and break
  sorting — and reports index paths for the first row of a section.
- A deserialized `ManagedObjectID` no longer re-resolves its store identifier.
- Composite attributes read back the values they were saved with. A composite has no column
  of its own — it decomposes into one column per element — so `SQLFetchRequestContext` rebuilds
  the nesting by resolving each column name through `compositeAttributeNameToSQLProperty`, which
  maps an element name back to the composite that owns it, and therefore to the composite's own
  description. Coercing an element's scalar against that description reached the
  `compositeAttributeType` branch of `ManagedObject::coercedValue()`, which answers a `Dictionary`
  for anything that is not one already, so every element was replaced by an empty one: a position
  saved as `{"x": 2510.0, "y": 63.0}` fetched back as `{"x": [], "y": []}` while the columns still
  held the right numbers. Only the read path was affected, which made it look like the store had
  lost the data rather than mistyped it on the way out. Each element is now coerced against its
  own description.
- A string attribute's `minValue` and `maxValue` constrain its length, not its collation order.
  `PropertyDescription::$validationPredicates` compared the value itself against the bound, so a
  string attribute compared a string to a number: PHP's numeric coercion made `"a"` satisfy a
  minimum of 2 and `"abcde"` satisfy a maximum of 4, and the constraint passed everything it was
  meant to reject. A string attribute now wraps the key path in `length:`; numeric attributes
  still compare the value, and `regex` still matches the value rather than its length.
- Atomic stores can now resolve a to-one relationship whose inverse is to-many. That combination
  had no branch in `AtomicStore::newValueForRelationship`, so once a fault was fulfilled — which
  re-faults every to-one — the relationship read as `null` for the rest of the session. Mutating
  any attribute on a saved object was enough to trigger it. The SQL store was never affected.
- The XML store no longer serializes an unresolved relationship fault as an empty relationship.
  Saves that only change attributes preserve stored references, while explicitly assigning an
  empty set or `null` still clears the relationship.
- Assigning a to-one relationship maintains the to-many inverse. Three of the four cardinality
  combinations in `ManagedObject::setValueForKey()` already did; the to-one whose inverse is
  to-many did not, so reassigning an object between owners left it in both, and clearing the
  to-one left it in the one it had just left. Hydrating from an atomic store now describes the
  cache node the way `SQLFetchRequestContext` describes a row — inserted, not a fault, stable —
  which is what lets the assignment resolve the old value and know which inverse to take the
  object out of. The maintenance deliberately does not dirty the inverse: the to-one assignment
  already marked the side that owns the value, and marking the other one as well makes the save
  rewrite each member's foreign key from the set, so the owner being moved away from would write
  its own emptiness over the link just established.
- Assigning a to-one maintains the inverse on an object that has never been saved. The
  maintenance was gated on the object being awake from a fetch and already inserted, which no
  newly created object is, so the in-memory graph disagreed with itself until the next save and
  read. An object with no row behind it has nothing to fault in, so its primitive value is
  already the whole truth and the gate now admits it.
  Two things had to follow. Nullifying a to-many no longer blanks a member's to-one when that
  member has already been given a new owner — the removal being processed is frequently the old
  owner losing a member precisely because it was reassigned, and clearing it there undid the
  assignment that caused the removal. And unlinking no longer moves an unsaved object from the
  inserted set to the updated set: there is no row to update, so the object was dropped from the
  save request and never written at all. Reassigning a to-one before the first save previously
  persisted the owner the object was moved *away* from, on both store families.
- A fetch against an atomic store no longer reports the objects it returned as updated. The
  stored snapshot is applied with change notifications suppressed, as the SQL store does, so a
  fetch that changes nothing leaves the context with no pending changes.

### Removed

- Removed migration-option placeholders that had no runtime implementation.

### Documentation

- Added `docs/` covering how to define a model, configure the MariaDB store and write migrations.
- Added `SECURITY.md` and `CONTRIBUTING.md`.
- Recorded why `declare(strict_types=1)` is absent from 80 of the 202 files in `src/`, in both
  `CONTRIBUTING.md` and `CLAUDE.md`. Next to Foundation and Service, where every file declares
  it, the omission reads as neglect; it is load-bearing. The store boundary marshals values whose
  PHP type is decided at runtime by the model's `AttributeType`, not at compile time —
  `ManagedObject::coercedValue()` casts a `mixed` through `(string)`, `(int)` or `(float)`
  according to the attribute it is reading, and `XMLObjectStore` hands the result to
  `DOMDocument::createElement()`, which requires `string`; coercive mode is what lets an integer
  attribute reach the DOM as text. Declaring strict types across all 80 turns 129 of the 438 tests
  into `TypeError`s, which is a measurement rather than an estimate. The split is principled and
  the note says so: the enums, the request and result value objects and the leaf types already
  declare it, and a new file of that kind should. `CONTRIBUTING.md` lists a blanket sweep under
  what the framework will not accept, since that is the change the note exists to decline.
