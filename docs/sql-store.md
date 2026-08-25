# Configuring the SQL store

The SQL store is the production backend. It is an incremental store: it loads and saves in
chunks rather than rewriting a whole file, which is what makes faulting and batching useful.

## Connection settings

The SQL layer reads its connection settings from the **process environment**, which
Foundation's `ProcessInfo` populates from a `.env` file in the project root.

| Variable | Default | Meaning |
|---|---|---|
| `SQL_SCHEMA_NAME` | *(required)* | Database/schema name |
| `SQL_SCHEMA_HOST` | `127.0.0.1` | Server host |
| `SQL_SCHEMA_CREDENTIAL_USER` | `root` | User |
| `SQL_SCHEMA_CREDENTIAL_PASSWORD` | *(none)* | Password |

`SQL_SCHEMA_NAME` has no default: if it is absent the store raises an
`InternalInconsistencyException` naming the missing variable.

```ini
# .env
SQL_SCHEMA_NAME=my_application
SQL_SCHEMA_HOST=127.0.0.1
SQL_SCHEMA_CREDENTIAL_USER=app
SQL_SCHEMA_CREDENTIAL_PASSWORD=secret
```

`ProcessInfo` parses `.env` **once per process and caches it**. Changing the file mid-process
has no effect, which matters for test suites that want to point at different databases — they
have to share one name per process.

The schema is created and kept up to date by the framework. You never write DDL, and consumer
code never writes SQL: statements are generated from `FetchRequest` predicates and sort
descriptors.

## Building the stack

### With `PersistentContainer`

`PersistentContainer` assembles the whole stack — model, coordinator, store, context — and is
the intended entry point for an application.

```php
use Sabatier\CoreData\PersistentContainer;
use Sabatier\CoreData\PersistentStoreDescription;
use Sabatier\Foundation\Error;
use function Sabatier\Foundation\fatal_error;

$container = new PersistentContainer("my_application");
$container->loadPersistentStores(function (PersistentStoreDescription $description, ?Error $error): void {
    if ($error) {
        fatal_error("Unable to load persistent stores: $error");
    }
});

$context = $container->viewContext;
```

Two things worth knowing about the constructor:

- **The name doubles as the store name.** For a SQL store the URL becomes `sql://<name>`, so
  the container's name is the database name unless you configure a description yourself.
- **The model is looked up by name** in the bundle (`<name>.mom` / `<name>.momd`) unless you
  pass a `ManagedObjectModel` explicitly as the second argument. Passing the model overrides
  the lookup, which is what you want when the model is built in code.

The store type comes from the bundle's `Info.plist` — the `CFBundleTypeName` entries under
`CFBundleDocumentTypes` — and defaults to the SQL store when that key is absent. The container
also enables automatic migration and automatic mapping-model inference on every description it
creates.

The completion handler runs **once per store**, so with several configured stores it is called
several times.

### With a coordinator directly

For finer control — several stores, a specific configuration, explicit options — build the
stack by hand:

```php
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\InferMappingModelAutomaticallyOption;
use const Sabatier\CoreData\MigratePersistentStoresAutomaticallyOption;

$coordinator = new PersistentStoreCoordinator($model);
$coordinator->addPersistentStoreWithType(
    PersistentStoreType::sql,
    null,                              // configuration name, or null for all entities
    new URL("sql://my_application"),
    new Dictionary([
        MigratePersistentStoresAutomaticallyOption => true,
        InferMappingModelAutomaticallyOption => true,
    ]),
);

$context = new ManagedObjectContext();
$context->persistentStoreCoordinator = $coordinator;
```

## Store options

Passed in the options dictionary when adding a store.

| Constant | Effect |
|---|---|
| `PersistentStoreIDOption` | Explicit store identifier, used in `ManagedObjectID`s and as a cache namespace. Derived from the URL when absent |
| `ReadOnlyPersistentStoreOption` | Reject writes. Default `false` |
| `PersistentStoreTimeoutOption` | Seconds to wait for a connection before failing |
| `PersistentStoreCacheStalenessIntervalOption` | How long cached snapshots stay valid, in seconds. Default 3600 |
| `ManagedObjectModelURLOption` | Load the model from a specific URL, for migration against a known version |
| `ValidateXMLStoreOption` | XML store only: validate against the DTD on open |

The migration options are documented in [Migrations](migrations.md).

## Concurrency

A context belongs to a queue. `ManagedObjectContextConcurrencyType` distinguishes the main-queue
context from a private-queue one, and `performBlock()` runs work on the context's own queue.

`PersistentContainer::newBackgroundContext()` returns a private-queue context parented to the
view context, set up to consume `didSaveObjectsNotification` automatically. Use it for writes
that should not block the foreground.

A child context saves **into its parent**, not to the store. To reach the database you have to
save every context in the chain up to the one whose parent is the coordinator.

## Fetching

Prefer the generated request over building one by hand:

```php
$request = Employee::fetchRequest();
$request->predicate = Predicate::format("lastName BEGINSWITH \"A\"");
$request->fetchBatchSize = 50;

$employees = $context->fetch($request);
```

A bare `new FetchRequest()` with no entity name resolves its context from the current operation
queue, and fails outside a running application — `MyClass::fetchRequest()` avoids that.

`fetchBatchSize` returns a `BatchFaultingArray`: object IDs are fetched up front and full objects
are materialized a batch at a time as you iterate, which keeps a large result set out of memory.

`FetchRequestResultType` controls what comes back — managed objects (the default), object IDs,
dictionaries, or a count. Ask for `countResultType` rather than fetching objects to count them.

### String comparison semantics

String operators are **case-sensitive by default**, matching Foundation's in-memory evaluation of
the same predicate; the `[c]` modifier (`BEGINSWITH[c]`) asks for case-insensitivity. This
matters because a predicate has to select the same objects whether it is satisfied from the
database or evaluated in memory.

Two consequences worth knowing:

- A case-sensitive `BEGINSWITH` is emitted as a case-insensitive `LIKE` ANDed with a
  `LIKE BINARY`. The first term lets the optimizer range-scan an index built on a
  case-insensitive collation; the second refilters to the exact answer. The result set is the
  same either way.
- **`LIKE` is not portable across layers.** Through the store its wildcards are escaped to
  literals, and in memory the pattern is compiled as an anchored regular expression — so a
  wildcarded `LIKE` matches nothing through the store, in either syntax. Only a wildcard-free
  pattern behaves identically in both. Use `BEGINSWITH` / `CONTAINS` / `ENDSWITH` for portable
  wildcard searches.

Literal `%` and `_` in a value are handled correctly for exact matches, comparisons and `IN`;
they are only treated as wildcards where the operator is a LIKE-family one.

## Batch operations

`BatchInsertRequest`, `BatchUpdateRequest` and `BatchDeleteRequest` operate directly on the store
and bypass the object graph. They are fast and they do **not** update in-memory objects, fire
delete rules, or run validation — refresh or discard affected contexts afterwards.

## Conflict resolution

Optimistic locking is available on the SQL store: each object carries a version that the store
persists and reads back, so `ConflictDetectionService` can compare a fetched baseline against the
current row. When they disagree, the context's `MergeStrategy` decides:

| Strategy | Resolution |
|---|---|
| `ObjectTrumpStrategy` | In-memory changes win |
| `StoreTrumpStrategy` | Stored values win |
| `OverwriteStrategy` | Last writer wins |
| `RollbackStrategy` | Discard the in-memory changes |

The default policy raises an error instead of merging. Atomic stores (XML, binary) do not persist
the version and degrade to "no conflict" — locking there would be meaningless, since the store
*is* its in-memory node cache and a save rewrites the whole file.

## Row caching

`RowCache` fronts the store with a snapshot cache. `DefaultRowCache` is in-process and needs
nothing; `APCuRowCache`, `RedisRowCache` and `MemcachedRowCache` back onto their respective
servers, and `NullRowCache` disables caching. Entries expire per
`PersistentStoreCacheStalenessIntervalOption`.
