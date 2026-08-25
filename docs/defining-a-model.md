# Defining a model

A managed object model is the schema the framework works from: which entities exist, what
they store, and how they relate. It is built at runtime from `EntityDescription` objects and
does not require a schema file, though it can be loaded from one (see
[Model versions and files](#model-versions-and-files) below).

## The pieces

| Class | Describes |
|---|---|
| `ManagedObjectModel` | The whole schema — a set of entities |
| `EntityDescription` | One entity: its name, its properties, its backing class |
| `AttributeDescription` | A scalar value (string, integer, date, …) |
| `RelationshipDescription` | A reference to another entity |
| `FetchedPropertyDescription` | A collection defined by a predicate rather than a foreign key |
| `CompositeAttributeDescription` | A value assembled from several other attributes |

`AttributeDescription`, `RelationshipDescription` and `FetchedPropertyDescription` all extend
`PropertyDescription`, which is why an entity's `properties` is one flat collection.

## A minimal model

Every entity needs a `ManagedObject` subclass named in `managedObjectClassName`. The class can
be empty — properties are supplied by the model, not declared on the class.

```php
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;

final class Employee extends ManagedObject
{
}

$lastName = new AttributeDescription();
$lastName->name = "lastName";
$lastName->type = AttributeType::string;

$hiredOn = new AttributeDescription();
$hiredOn->name = "hiredOn";
$hiredOn->type = AttributeType::date;
$hiredOn->isOptional = true;

$employee = new EntityDescription();
$employee->name = "Employee";
$employee->managedObjectClassName = Employee::class;
$employee->properties = new ArrayClass([$lastName, $hiredOn]);

$model = new ManagedObjectModel();
$model->entities = new ArrayClass([$employee]);
```

## Attribute types

`AttributeType` is the enum of storable value types. Use it rather than raw strings.

| Case | Notes |
|---|---|
| `string` | |
| `integer16`, `integer32`, `integer64` | Pick the width you need; migrations can widen but not narrow |
| `decimal` | Exact decimal, for money |
| `double`, `float` | Binary floating point |
| `boolean` | |
| `date` | Backed by Foundation's `Date` |
| `binaryData` | |
| `uuid`, `uri` | |
| `transformable` | Serialized arbitrary value |
| `objectID` | A reference to another object's identity |
| `compositeAttributeType` | See `CompositeAttributeDescription` |

### Optional attributes and default values

`isOptional` defaults to `false`, and a non-optional attribute is not enforced late at save
time — it is satisfied **eagerly at construction**. `ManagedObject::hydrateProperties()` runs
`resolveInitialAttributeValue()`, which assigns a type-appropriate default (`""` for a string,
`0` for an integer) instead of leaving the value null. So a required attribute is already
non-null by the time you save.

This is a deliberate departure from Apple's fail-late validation. If you want a value to be
genuinely absent until set, mark it `isOptional = true`.

Note that `binaryData`, `objectID` and `compositeAttributeType` have no type default, so a
required attribute of one of those types can still be null at save time.

## Relationships

A relationship needs a destination and, in practice, an inverse. Declare both sides and point
each at the other; the framework maintains them together, so setting one side updates the other.

```php
use Sabatier\CoreData\RelationshipDescription;

// Department --< Employee (one department, many employees)
$employees = new RelationshipDescription();
$employees->name = "employees";
$employees->lazyDestinationEntityName = "Employee";
$employees->lazyInverseRelationshipName = "department";
$employees->isToMany = true;

$department = new RelationshipDescription();
$department->name = "department";
$department->lazyDestinationEntityName = "Department";
$department->lazyInverseRelationshipName = "employees";
$department->maxCount = 1;   // to-one
```

The `lazy…Name` properties exist so two entities can refer to each other while the model is
still being assembled — neither has to be constructed first. Cardinality is `isToMany = true`
for a collection, or `maxCount = 1` for a to-one.

### Delete rules

`DeleteRule` decides what happens to the destination when the source object is deleted:

| Case | Effect |
|---|---|
| `nullifyDeleteRule` | Clear the inverse reference (the usual choice) |
| `cascadeDeleteRule` | Delete the destination objects too |
| `denyDeleteRule` | Refuse the delete while the relationship is non-empty |
| `noActionDeleteRule` | Leave the destination untouched — you are responsible for consistency |

```php
$employees->deleteRule = DeleteRule::cascadeDeleteRule;
```

On the SQL store the foreign key's `ON DELETE` clause is derived from the **inverse** (to-many)
side's rule, not from the to-one side.

### Optional to-many relationships

An additive mutator materializes an absent to-many relationship only when that relationship is
`isOptional` — being optional is what permits the absent-to-present transition. On a
non-optional to-many, add to a collection that already exists.

## Composite attributes

A `CompositeAttributeDescription` groups several attributes into one value, stored in a single
column. Its `elements` are the constituent attributes.

## Fetched properties

A `FetchedPropertyDescription` is a collection computed from a predicate rather than stored as a
foreign key — the equivalent of a saved query hanging off an entity. It is evaluated on access
and is read-only.

## Constraints and indexes

### Uniqueness

`EntityDescription::$uniquenessConstraints` is an array **of arrays**: each inner array is one
constraint, holding either `AttributeDescription` objects or attribute names. An inner array with
several entries is a composite constraint — the tuple must be unique, not each column
separately.

```php
// "code" alone must be unique; (year, sequence) must be unique together.
$invoice->uniquenessConstraints = new ArrayClass([
    new ArrayClass(["code"]),
    new ArrayClass(["year", "sequence"]),
]);
```

Constraints form part of the entity's version hash, so adding one is a model change that
triggers a migration. A store that cannot enforce uniqueness refuses to open such a model.
Violations at save time surface as `ConstraintConflict` and are resolved by the context's merge
policy. Uniqueness checking is expensive: prefer one constraint per entity hierarchy.

### Indexes

`FetchIndexDescription` is an index over one or more `FetchIndexElementDescription`s. Elements
must share a collation type; mixing R-tree and non-R-tree elements is rejected.

`FetchIndexDescription::$partialIndexPredicate` is currently accepted but not implemented — it
is ignored rather than applied.

## Model versions and files

A model can be serialized to a file. The extensions are:

| Extension | Contents |
|---|---|
| `.mom` | One managed object model |
| `.momd` | A bundle of `.mom` versions (a version package) |
| `.cdm` | A mapping model, for custom migrations |

`ManagedObjectModelBundle` locates a model in a bundle, by name for a single `.mom` or by
version for a `.momd` package. Version identity is what drives migration: each entity carries a
version hash, and a mapping model records the hashes of the entities it maps, which is how the
framework decides that a given mapping model applies to a given pair of models. See
[Migrations](migrations.md).

## Where the model is used

A model is handed to a `PersistentStoreCoordinator`, which mediates between it and the stores:

```php
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\URL;

$coordinator = new PersistentStoreCoordinator($model);
$coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, new URL("sql://my_database"));

$context = new ManagedObjectContext();
$context->persistentStoreCoordinator = $coordinator;
```

`PersistentContainer` does all of the above for you and is the better entry point for an
application; see [Configuring the SQL store](sql-store.md).
