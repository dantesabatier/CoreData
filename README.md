# Sabatier CoreData

**Sabatier CoreData** is an object-graph management and persistence framework for PHP 8.5,
inspired by Apple's Core Data. It tracks model objects and their relationships, coordinates
changes through managed object contexts, and persists them without requiring application code to
write SQL. The production SQL store targets MariaDB.

## Key features

- **Object-graph management** — create, fetch, update and delete related model objects through an
  internally consistent context.
- **Lazy loading and batched fetching** — defer data loading until it is needed and iterate large
  result sets without materializing every object at once.
- **Relationship management** — maintain to-one and to-many relationships, inverse relationships
  and Nullify, Cascade, Deny and No Action delete rules.
- **Change tracking and undo** — inspect pending changes, save or roll them back, and integrate an
  undo manager when the application needs undo and redo.
- **Conflict resolution and constraints** — enforce uniqueness constraints and choose a supported
  merge policy for optimistic-locking conflicts.
- **Model migration** — infer supported schema changes or supply custom migration behavior.

## Requirements

- PHP `^8.5`.
- The `dom`, `intl`, `mbstring`, `pdo` and `pdo_mysql` PHP extensions.
- MariaDB for the SQL store.

The `sabatier/foundation` dependency is installed by Composer.

## Installation

```bash
composer require sabatier/coredata
```

## Quick start

The following example assumes that `$model` has been built as shown in
[Defining a model](docs/defining-a-model.md), and that it contains an `Employee` entity backed by
an `Employee` subclass of `ManagedObject`.

```php
use Sabatier\CoreData\PersistentContainer;
use Sabatier\CoreData\PersistentStoreDescription;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Error;
use function Sabatier\Foundation\fatal_error;

$container = new PersistentContainer("my_application", $model);
$container->loadPersistentStores(function (PersistentStoreDescription $description, ?Error $error): void {
    if ($error) {
        fatal_error("Unable to load persistent store: $error");
    }
});

$context = $container->viewContext;

$request = Employee::fetchRequest();
$request->fetchBatchSize = 50;
$employees = $context->fetch($request);

foreach ($employees as $employee) {
    echo $employee->lastName;
    $employee->lastAccessDate = new Date();
}

if ($context->hasChanges) {
    try {
        $context->save();
    } catch (\Throwable $error) {
        // Handle validation or merge conflicts.
    }
}
```

Prefer the model class' `fetchRequest()` method over constructing an unscoped request. It resolves
the correct entity without depending on ambient queue state.

## Public architecture

- **Model and objects** — `ManagedObjectModel` contains `EntityDescription` metadata;
  application entities subclass `ManagedObject`.
- **Context** — `ManagedObjectContext` is the primary interface for inserting, fetching, changing
  and deleting model objects. It also owns the merge policy and undo state.
- **Persistence** — `PersistentContainer` assembles the model, coordinator, stores and view
  context. `PersistentStoreDescription` configures each store.
- **Requests** — `FetchRequest` describes reads; batch insert, update and delete requests provide
  direct bulk operations when object-graph callbacks are not required.

Most applications should start with `PersistentContainer`. The lower-level coordinator APIs are
available for applications that need several stores or explicit store configuration.

## Documentation

- [Defining a model](docs/defining-a-model.md) — entities, attributes, relationships, delete rules
  and constraints.
- [Configuring the MariaDB store](docs/sql-store.md) — connections, store options, fetching,
  concurrency, conflict resolution and caching.
- [Migrations](docs/migrations.md) — inferred and custom mappings and staged migrations.
- [Security policy](SECURITY.md) — supported versions and private vulnerability reporting.
- [Changelog](CHANGELOG.md) — release notes and compatibility-relevant changes.

## License

Sabatier CoreData is available under the [MIT License](LICENSE.md).
