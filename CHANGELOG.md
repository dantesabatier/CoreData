# Changelog

All notable changes to Sabatier CoreData are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.3] - 2026-10-08

### Fixed

- A new object linked to a saved one whose inverse has not been read is saved. Leaving the unread inverse as a fault also skipped the announcement of the insertion, which is how the context learns the new object has to be saved, so several new objects added through the same owner kept only the first. The fault is still left alone; only the announcement is restored.

## [1.2.2] - 2026-10-08

### Changed

- A table's columns run in a fixed order: the primary key, the entity name and the version, then the plain attributes, the composites' elements, the derived attributes, and the foreign keys last. A derived attribute computes from the plain and composite attributes, so those columns are declared before it. A migration repositions the columns of every entity it transforms; an entity that does not migrate keeps its current order.

### Fixed

- A composite's element columns take the composite's place in the table. They used to be appended behind the foreign keys, both when a table was created and when a migration added the composite.
- A migration that changes a derived attribute's expression while adding columns no longer fails with "Unknown column". The changed derived attribute was recreated before the new columns existed; it is now recreated with the other derived attributes once they do.

## [1.2.1] - 2026-10-08

### Fixed

- A composite attribute set on a new object reaches its columns. The INSERT skipped composites outright while the UPDATE expanded them into their element columns, so the row kept the columns' database defaults until the object was saved a second time.
- A composite listed in a nested serialization is read through its elements' columns. The SQL asked for a column named after the composite, which has none, and failed with "Unknown column"; inside a to-many, the row reader also looked up the composite's owner by the parent's key and dropped its values.

## [1.2.0] - 2026-10-07

### Changed

- `ManagedObjectID` adopts Foundation's `Hashable`, with the entity name and the reference object as its hash value, so a `Set` of object IDs searches its index instead of comparing every member. The reference object announces its changes, and a set holding an ID moves it to its new key when the ID is reconciled with an existing row.
- `ManagedObject` and `IncrementalStoreNode` adopt `Hashable` through their object ID, so the context's sets of inserted, updated and changed objects, and of pending changes, search their index instead of comparing every member. Replacing an object's ID announces the change, and a set holding the object moves it to its new key. An ID reconciled in place with an existing row is not followed yet: a set of objects or of pending changes keeps it under the reference it gave up.
- A to-many relationship answers `containsElement()`, `indexOf()` and `member()` by looking up the object's ID among the IDs it stores. It used to fault every member into the context to compare it with the object; on a relationship of a few hundred members that was seven to twenty times slower.
- Inserting and saving 2,000 objects took 336 seconds, recording each change by searching every change already pending; it now takes 11.
- Requires Foundation 1.2.1, whose `Set` locates an indexed member without comparing it with every element.

## [1.1.4] - 2026-10-05

### Fixed

- Reconciling an unread to-many with the objects pending in the context no longer adds a subentity to a concrete destination. The store answers a concrete entity with its own rows only, but the reconciliation accepted any kind of it, so a subentity sharing the inverse joined the to-many as one more member. A concrete destination now takes only its own entity; an abstract one still takes its subentities. This fix first shipped in 1.1.2 and was removed by the revert in 1.1.3, which it did not depend on.

## [1.1.3] - 2026-10-05

### Added

- `ManagedObjectID::compare()` orders object IDs by entity name and then by reference, so they can be sorted without comparing the objects themselves.

### Removed

- The changes of 1.0.9 to 1.1.2 are reverted: re-keying repointed object IDs, `Hashable` on `ManagedObjectID`, retaining objects with unprocessed changes, and keeping subentities out of a concrete destination. Re-keying made the context observe every object ID with a closure that captured it, so comparing two object IDs with `<=>` or `==` walked the whole graph and stopped with "Nesting level too deep". This release behaves as 1.0.8.

## [1.1.2] - 2026-10-05

### Fixed

- Reconciling an unread to-many with the objects pending in the context no longer adds a subentity to a concrete destination. The store answers a concrete entity with its own rows only, but the reconciliation accepted any kind of it: in Raya, a new task, which inherits from time frame and shares its inverse to the shift, joined the shift's time frames, and the shift took the open task as its last one, losing its end date and counting its hours up to the moment of the save. A concrete destination now takes only its own entity; an abstract one still takes its subentities.

## [1.1.1] - 2026-10-05

### Fixed

- With `retainsRegisteredObjects` off, an attribute change made to an object that was released before the save is saved. The context recorded the change by object ID alone and rebuilt the object from the store when it processed it, so the change was silently lost. An object with unprocessed changes is now retained until they are processed, as the property documents.
- `reset()` forgets the objects the context had already released and the observations of their object IDs, and discards the changes it had not yet processed. With `retainsRegisteredObjects` off, it used to leave all of them behind.

## [1.1.0] - 2026-10-05

### Changed

- `ManagedObjectID` adopts Foundation's `Hashable`, so a `Set` of object IDs, and the one a to-many fault builds from the store's IDs, searches its index instead of comparing every member. Its reference object is rewritten in place when the object is reconciled with an existing row, and the set moves the ID to its new key when that happens.
- Requires Foundation 1.2.0, which introduces `Hashable` and the `Set` index.

## [1.0.9] - 2026-10-05

### Changed

- `ManagedObjectID::isEqual()` compares the store identifier, the entity name and the reference object exactly instead of through a case-insensitive collation. They are generated, never typed by hand, and the collation made each comparison some 57 times slower, on the path every `Set`, `containsElement()` and `indexOf()` over object IDs walks, including the one a to-many fault builds from the store's IDs. Two IDs whose components differ only in case are no longer equal.

### Fixed

- An object ID repointed at an existing row — an insert that collides on a unique index during a save, or an `objectID` assigned through key-value coding — is now found under the reference it adopted. The context and the store indexed it by the reference it had before, so the context answered a second instance for the row and kept the object after `reset()`, and the store answered the repointed ID for the reference it gave up: a different row later stored under that reference was read back as the adopted one. Both now observe the reference and move the ID to its new key.

## [1.0.8] - 2026-10-03

### Fixed

- Serializing a graph is no longer slowed down by the reconciliation 1.0.6 added to to-many faults. Each fault read the context's registered objects through `registeredObjects`, which builds a `Set` and compares every object against every other, and a serialization fires one fault per to-many it reads: a report of 18 tasks took twice as long to serialize. The context now reads its own association table, whose values are already distinct.

## [1.0.7] - 2026-10-03

### Fixed

- An equality or inequality against nil with the nil on the left (`nil == qty`, or a substitution variable that resolved to nil compared against a value) produced `NULL IS 'a'`, which SQL rejects as a syntax error. The operands now swap so the null lands on the right of `IS` / `IS NOT`.

## [1.0.6] - 2026-10-03

### Fixed

- Assigning a to-one relationship no longer empties the to-many inverse of a destination whose inverse had not been read yet. The insertion side took that set through `mutableSetValueForKey`, which hands over the unresolved set without loading it, and unioned the new object into it; that cleared the set's fault flag, so the destination then read only the new object and the members already in the store were lost from the graph. Anything that summed over the relationship in `willSave()` saw them vanish: in Raya, creating a process reset its task's counters to zero. An unread inverse is now left as a fault, on the owner gained and on the owner left, and the context reconciles it when it fires: the members the store returns are joined with the objects inserted or reassigned in the context, read from the to-one each one holds in memory. Loading the inverse at assignment time instead would have read every member of every destination — a process assigned to its machine, shift and area loaded tens of thousands of processes it never used.
- Reassigning or clearing a to-one relationship removes the object from the inverse of the owner it left. The removal ran against the owner being assigned, so the previous owner kept listing the object.

## [1.0.5] - 2026-10-01

### Fixed

- A `validate<Key>()` hook declared with a nullable scalar type, such as `?float`, no longer fails with a `TypeError` when the attribute is cleared. The hook ran before the value was coerced, so it received the `Nil` that a `null` in a JSON body becomes, or a wrapped `Number`. Attribute values now go through the same conversion `resolveInitialAttributeValue()` applies before the hook runs: `Nil` and `null` on a non-optional attribute take its default, and `undefined`, `binaryData`, `objectID` and composite attributes are passed through untouched. The coercion after the hook is unchanged, so a hook that turns an integer into an enum case keeps working. A hook that expected the raw value of a `date`, `uuid` or `uri` attribute now receives the converted `Date`, `UUID` or `URL`.

## [1.0.4] - 2026-09-28

### Fixed

- Serializing a managed object no longer recurses without end when the serialization shape names a relationship on both of its sides. The author emitted its books, each book emitted its author, and the two called each other until the process ran out of memory; a nested object now leaves out the relationship it was reached through.

## [1.0.3] - 2026-09-25

### Fixed

- A fetch against a SQL store whose predicate is malformed now fails with an
  `InternalInconsistencyException` whether or not assertions are enabled: an `IN` whose right
  side is empty or not a collection, a `BETWEEN` whose right side is not exactly two values, and
  a collection operator (`@count`, `@sum`, …) applied to a key path that is not a to-many
  relationship. These checks were `assert()` calls, so with `zend.assertions` disabled, as in
  production, the fetch went on to build invalid SQL and failed later with a less useful error.

## [1.0.2] - 2026-09-24

### Fixed

- `Info.plist` reports the released version. It still read `0.3` after `1.0.1` was published, because nothing derives the bundle version from the tag.
- A `ManagedObjectContext` whose `save()` threw — a validation failure, or a
  `ManagedObjectContextWillSave` observer that denied the save — no longer ignores every later
  `save()` on it. The context kept believing a save was in progress, so each later call returned
  `true` without writing anything.
- A `save()` made from a `ManagedObjectContextDidSave` observer now reaches the store. The
  notification was posted while the outer save was still in progress, so the nested call returned
  `true` without writing, and its changes stayed pending while `hasChanges` read `false`.
- Deleting an object that has never been saved no longer breaks the next `save()`. The context
  scheduled a store delete for a row that never existed, and an atomic store (XML, binary) threw
  "Unable to delete an uncached object"; this held too after a `save()` that threw, since that save
  had already given the object a permanent ID. Such an object is now forgotten: it leaves
  `insertedObjects` and the context's registered objects and never enters `deletedObjects`, so it
  no longer collides with a sibling's unique value either. A cascade that reaches it forgets it
  the same way, and a cascade that cycles back to it ends.

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
