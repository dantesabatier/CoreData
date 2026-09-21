# Changelog

All notable changes to Sabatier CoreData are documented in this file.

The project follows [Semantic Versioning](https://semver.org/). Until the first stable release,
entries remain under **Unreleased**.

## [1.0.1] - 2026-09-21

### Fixed

- An aggregate derivation no longer joins the relationship its correlated subquery already
  resolves. The join multiplied the rows the outer query carried and the subquery was
  re-evaluated over each one, so a fetch whose serialization reached three to-many
  relationships and their contacts gathered twelve joins under a `DISTINCT` that discarded the
  surplus only after the aggregates had been computed across it. A traversal still gets the
  join that defines its alias, without which its column cannot resolve.

## [1.0.0] - 2026-09-18

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
- `PersistentStoreCoordinator::setURL()` relocates a store towards the URL it was asked for. Its
  arguments were inverted, so the call moved the store to where it already was.
- The primary key is no longer written inside `ON DUPLICATE KEY UPDATE`. A unique-index collision
  therefore renumbered the surviving row's primary key to the colliding object's reference,
  orphaning every foreign key still pointing at the old value — silent referential data loss with
  no error anywhere. The same defect masked `resolveUpsertConflicts` into looking like dead code:
  the key had already been rewritten to the inserted object's reference, so its guard compared a
  reference with itself and never assigned.
- Specialised SQL indexes no longer emit duplicate and invalid DDL. A spatial or binary index was
  written as `ADD CONSTRAINT … SPATIAL INDEX` carrying a sort order, which MariaDB rejects
  outright.
- Assigning a to-one relationship no longer hydrates the object graph to maintain its inverse.
  The membership check read the inverse through `valueForKey`, which fires the fault: a payload
  naming one object pulled in 2415, and a save from a page that touched a deep graph exhausted
  memory instead of completing. The insertion side now takes the set through
  `mutableSetValueForKey`, which does not fault, and the removal side only looks at an inverse
  that is already resolved.
- `SQLGenerator::groupedObjects()` orders the save groups by dependency again. An entity carrying
  a foreign key must be written after the row it points at, and that had been reinterpreted as an
  ordinary sort, so a save could ask the server to store a reference to a row that did not exist
  yet. Dependency is transitive and a comparator is not — `usort` only ever compares pairs, so an
  unrelated entity sorting between two related ones means the pair that matters is never compared
  — and the ordering is now a topological sort, with a cycle falling back to name order rather
  than dropping the groups it cannot order.
- An ordered relationship in an atomic store sorts by the first attribute of the entity it points
  at. It read that attribute off the inverse's destination, which is the entity the relationship
  starts *from*, and then asked the destination's cache nodes for it; a node answers only for its
  own entity's properties, so any ordered relationship whose two entities did not happen to name
  their first attribute identically raised `UndefinedKeyException`. Only atomic stores were
  affected: the SQL store keeps a dedicated order column.
- A cancelled migration aborts instead of reporting success. `cancelMigrationWithError()`
  documents that `migrateStore()` aborts and returns the error; it stopped the passes but then
  carried on, saving the destination context, setting the progress to 1.0 and returning `true`.
  The error was only raised on the way *into* the next entity mapping, so it needed another
  mapping left to enter — cancelling on the last mapping of a pass, or in a model with a single
  one, fell straight through. Worse than the wrong return value, the save wrote whatever the
  policy had produced before it gave up, leaving a destination no policy vouched for. Raising it
  where the passes end fixes both halves, since reaching it before the save is what keeps the
  partial work off disk.
- `XMLObjectStore::metadataForPersistentStore()` reads the file it is given. It parsed a
  `DOMDocument` it had just constructed and never loaded the URL, so a valid store on disk
  answered with nothing at all; its sibling `setMetadata()` reads the file the same way and
  always did. A URL with no store behind it is the caller's error and now fails as one, naming
  the path, rather than reaching an assertion two levels down that production has switched off.

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
