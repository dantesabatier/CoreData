# Migrations

When a store was written with one model and is opened with a different one, the schema has to
be reconciled before the store can be used. There is only ever **one migration engine**:
`MigrationManager` running a `MappingModel`. What differs between "lightweight" and "custom" is
only where that mapping model comes from.

## How a migration is triggered

Adding a store with the migration options set is what starts it. The coordinator compares the
model cached in the store against the model it was given; if they are incompatible it migrates
before returning.

```php
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\Dictionary;
use const Sabatier\CoreData\InferMappingModelAutomaticallyOption;
use const Sabatier\CoreData\MigratePersistentStoresAutomaticallyOption;

$coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $url, new Dictionary([
    MigratePersistentStoresAutomaticallyOption => true,
    InferMappingModelAutomaticallyOption => true,
]));
```

| Option                                                 | Effect                                                                              |
|--------------------------------------------------------|-------------------------------------------------------------------------------------|
| `MigratePersistentStoresAutomaticallyOption`           | Migrate on open when the models are incompatible                                    |
| `InferMappingModelAutomaticallyOption`                 | Infer a mapping model when none is found. Requires the option above                 |
| `PersistentStoreStagedMigrationManagerOptionKey`       | Supply a staged migration manager                                                   |

`PersistentContainer` sets the first two on every description it creates, so an application
built that way migrates automatically.

## Lightweight migrations

When no mapping model exists, the framework infers one by comparing the store's cached model with
the new one. This covers ordinary shape changes: adding or removing an entity, adding or removing
a property, renaming, and changing a relationship's cardinality.

Attribute **type** changes are the constrained part. The supported inferred conversions are:

| From                                                                                 | To                                                                            | Inferable |
|--------------------------------------------------------------------------------------|-------------------------------------------------------------------------------|-----------|
| Any type                                                                             | The same type                                                                 | Yes       |
| `integer16` / `integer32` / `integer64` / `decimal` / `double` / `float` / `boolean` | An integer, `decimal`, `double`, `float` or `string`                           | Yes       |
| Anything else                                                                        | A different type, including `date` or `uuid` to `string`, or any to `boolean` | No        |

Numeric conversions can widen *or narrow*: `integer64` to `integer16` is accepted even though
some values may not survive. Test the migrated data before production deployment. Anything
outside the table raises `InferredMappingModelException`; changing a relationship's destination
entity does too.

A useful asymmetry: numeric → `string` is inferable, but `date` → `string` is **not**, even
though a database will happily render a date as text.

## Custom migrations

When a mapping model already exists, nothing is inferred. `MappingModel::mappingModel()` finds
it in a bundle **by version information, not by file name**: an `EntityMapping` records the
version hashes of the entities it maps, and those hashes identify the model pair the mapping
applies to. Mapping model files use the `.cdm` extension.

Two checks run before either store is opened:

- `canMigrateWithMappingModel()` rejects a mapping model that is not structurally usable.
- `performSanityCheck()` rejects one whose recorded version hashes disagree with the models
  actually being migrated.

Both are deliberately permissive about what a hand-authored mapping model may leave out.

### Entity mapping types

`EntityMappingType` says what happens to an entity:

| Case                         | Meaning                                        |
|------------------------------|------------------------------------------------|
| `addEntityMappingType`       | New in the destination                         |
| `removeEntityMappingType`    | Gone from the destination                      |
| `copyEntityMappingType`      | Carried across unchanged                       |
| `transformEntityMappingType` | Changed, with property mappings describing how |
| `customEntityMappingType`    | Handled by your own policy class               |
| `undefinedEntityMappingType` | Not yet classified                             |

### Custom policies

An `EntityMapping` whose `mappingType` is `customEntityMappingType` must name an
`EntityMigrationPolicy` subclass in `entityMigrationPolicyClassName`. That policy produces the
destination values the framework cannot derive by itself — a value split across two attributes,
a unit conversion, a lookup against another entity.

The schema for a custom mapping is still reconciled from the destination model, exactly as for a
transformation. The policy supplies data, not DDL.

## The three passes

`MigrationManager` runs the mapping model in three passes and delegates each to an
`EntityMigrationPolicy`. **One policy instance per entity mapping, reused across all three
passes**, so a policy can accumulate state in the first pass and use it in the later ones.

| Pass        | Policy hooks                                                                |
|-------------|-----------------------------------------------------------------------------|
| 1. Create   | `begin()`, `createDestinationInstances()`, `endInstanceCreation()`          |
| 2. Relate   | `createRelationships()`, `endRelationshipCreation()`                        |
| 3. Validate | `performCustomValidation()`, `end()`                                       |

The passes are separated because relationships cannot be wired until every destination object
exists. In `createDestinationInstances()` you create the destination object and set its
attributes; in `createRelationships()` you connect it to objects other mappings created.

### Aborting a migration

A policy is the only place that knows a source row cannot be carried forward. Call
`cancelMigrationWithError()` on the manager from any hook to stop:

```php
public function createDestinationInstances(ManagedObject $sourceInstance, EntityMapping $mapping, MigrationManager $manager): bool
{
    if ($sourceInstance->legacyCode === null) {
        $manager->cancelMigrationWithError(new Error("MyApp", 1));
        return false;
    }
    return parent::createDestinationInstances($sourceInstance, $mapping, $manager);
}
```

`migrateStore()` then raises the error you supplied rather than returning, and the destination
context is never saved: a cancelled migration writes nothing, so the store is left as it was
instead of holding a half-written destination. Call `reset()` on the manager to clear the
cancellation if you intend to reuse the instance.

## Staged migrations

A migration can be expressed as a sequence of stages. Create a `StagedMigrationManager` with the
ordered stages and pass it under `PersistentStoreStagedMigrationManagerOptionKey`. Stages extend
`MigrationStage`:

- `LightweightMigrationStage` receives an ordered list of model version checksums suitable for
  inferred migration.
- `CustomMigrationStage` receives source and destination `ManagedObjectModelReference` objects.
  Its `willMigrateHandler` and `didMigrateHandler` let application code prepare or clean up data
  around the stage. An explicit mapping model, when needed, is discovered from the model version
  hashes in the application bundle.

Staging is how you move across several model versions in order, or interleave an inferable
change with one that needs code, without writing a single mapping model that spans every
version.

## Practical notes

- **Migration is not a rehearsal.** It alters the live schema in place. Take a backup of a
  production database before opening it with a new model.
- **The store records the model it was written with.** That cached model is what a later
  migration compares against, which is why a store migrated outside the framework will not
  line up.
- **After migrating, read through a fresh stack.** The coordinator that performed the migration
  still holds row-cache snapshots taken against the *old* schema, so fetching through it can
  miss a column that was renamed or reshaped. Open a new coordinator over the migrated store —
  which is what an application does anyway on its next launch.
