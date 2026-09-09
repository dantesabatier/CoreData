# Contributing

Thanks for your interest in Sabatier CoreData. This file describes how the project is built and
tested, and what a change is expected to carry with it.

For a security problem, do not open an issue — see [SECURITY.md](SECURITY.md).

## Requirements

- **PHP 8.5** or newer. The framework uses property hooks, asymmetric visibility and the pipe
  operator, so an earlier version will not parse it.
- **MariaDB** (or MySQL) reachable on `127.0.0.1`, for the SQL suites. Everything else runs
  without a server.
- **[Foundation](https://github.com/dantesabatier/Foundation)**, the sibling library that
  provides the collections, predicates, KVO and notifications this framework is built on.

The QA tools — PHPUnit, Psalm, Rector — are installed **globally** with Composer, not in the
project's `vendor/`, which only holds the autoloader. Do not expect `composer install` to
provide them.

## Getting set up

```bash
composer install
```

Then point the SQL suites at a scratch database by creating a `.env` in the project root:

```ini
SQL_SCHEMA_NAME=coredata_migration_test
```

Host and credentials default to `127.0.0.1` and `root` with no password. The suites drop and
recreate that database around every test, so give it a name you are willing to lose.

## Running the checks

```bash
phpunit
```

```bash
psalm --config=psalm.xml
```

```bash
rector process --dry-run
```

A change is expected to leave the suite green and Psalm reporting no errors. Rector's dry run is
advisory: it proposes modernisations, and not every proposal is wanted — `rector.php` skips the
ones that fight the framework's design.

There is **no formatter**. Match the surrounding code: PSR-12, double-quoted strings, short array
syntax, one-line property docblocks (`/** @var Type Description */`).

## Tests

Suites live in `tests/` as PHPUnit `TestCase` classes in the `Sabatier\CoreData\Tests` namespace,
configured by `phpunit.xml`. Warnings and notices fail the run, so a test that emits either is a
failing test.

A few things worth knowing before writing one:

- **Entity class names are global to the suite.** Every fixture class shares one namespace, so a
  second `Widget` is a fatal error at load time. Check first:
  `grep -rho "^final class [A-Za-z]* extends ManagedObject" tests/*.php`.
- **Persistence tests need a real store.** `MemoryObjectStore` has no `load()` and cannot be added
  to a coordinator; use an `XMLObjectStore` on a per-test temp file, or extend
  `SQLMigrationTestCase` for anything involving the SQL store or a migration.
- **Fetch through a subclass.** `MySubclass::fetchRequest()` resolves the entity for you; a bare
  `new FetchRequest("Entity")` resolves its context from the operation queue and dies outside a
  running application.
- **Release the stack in `tearDown`.** Assigning a coordinator registers the context as a
  notification observer, which keeps both alive for the whole process. Set
  `$context->persistentStoreCoordinator = null` when the test is done, or a large suite exhausts
  the database's connection limit.

### A green test is not yet a passing test

If a new test passes the first time it runs, that is a reason for suspicion rather than
confidence — it may be asserting something that was never in question. Break the code it covers
on purpose and confirm the test fails, then restore it. Several defects in this codebase were
found exactly that way, and one supposed defect turned out not to exist because removing the
"fix" broke nothing.

## Reporting a bug

Include the PHP version, the store type, and the smallest model that shows the problem —
attributes and relationships in code, the way the suites build them. If it involves the SQL
store, the generated statement helps; if it involves a migration, both model versions do.

## Submitting a change

- **Branch from `master`** and keep a pull request to one concern.
- **Add a regression test to the matching suite.** A bug fix without a test that would have caught
  it is incomplete.
- **Explain the cause in the commit message, not in a comment.** Comments carry the constraint a
  reader cannot see in the code; the story of the defect belongs in the commit. See the existing
  history for the tone — imperative subject, then why the change is shaped the way it is.
- **Say what you measured.** "Reverting the guard fails three tests" is worth more than a claim
  that the fix works.
- Update `CHANGELOG.md` under **Unreleased** when the change is visible to someone using the
  framework.

## What the framework will not accept

Some constraints are deliberate, and a change that violates one will be declined:

- **No SQL in consumer code.** Every statement is generated from a `FetchRequest`, its predicate
  and its sort descriptors. A new capability belongs in the generator, not in a hand-written
  query.
- **Never bypass `ManagedObjectContext`** to pre-populate an object's values. Objects are shells
  until a property is read, and faulting depends on that.
- **Prefer the typed enums** — `AttributeType`, `DeleteRule`, `FetchRequestResultType`,
  `PersistentStoreType` — over raw strings.
- **Prefer property hooks** to explicit getters and setters for computed or coerced values. Note
  that PHP forbids a hooked property in a `readonly` class: such a class drops the class-level
  `readonly` and marks each promoted property `readonly` instead.
- **No blanket `declare(strict_types=1)` sweep.** 80 of the 202 files in `src/` omit the
  declaration on purpose. The store boundary marshals values whose PHP type is decided at
  runtime by the model's `AttributeType`, not at compile time: `ManagedObject::coercedValue()`
  casts a `mixed` through `(string)`, `(int)` or `(float)` per attribute, and `XMLObjectStore`
  passes the result to `DOMDocument::createElement()`, which requires `string`. Declaring
  strict types across all 80 turns 129 of the 438 tests into `TypeError`s. A new enum, request
  or leaf value object should still declare it, as its peers already do — the exception covers
  the coercing core, not the whole tree.

## Licence

Contributions are accepted under the [MIT Licence](LICENSE.md), the same terms as the project.
