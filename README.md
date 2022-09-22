# Core Data

This is the first (as far as I know) open source implementation of *[Apple's Core Data](https://developer.apple.com/documentation/coredata)*, a wonderful, very complex and beautifully designed framework, responsable of many bundled applications, services and frameworks in macOS and iOS that I created using mostly my intuition and experience developing Cocoa applications.

I created this framework because I wanted to automate the process of dealing with the complexity of persistent data and is build on top of *[Sabatier's Foundation](https://github.com/dantesabatier/Foundation)*.

## What is Core Data?

Core Data is an [Object graph](https://en.wikipedia.org/wiki/Object_graph) and [Persistence framework](https://en.wikipedia.org/wiki/Persistence_framework) that enables the organization and manipulation of data from an entity-attribute relational model.

Core Data provides two abstract types of persistent stores:

- Atomic, intended to handle data sets that can be expressed in memory, favors simplicity over performance.
- Incremental, to manage large and/or shared data sets.

Core Data also provides the implementation of two specific persistent store types, XML and SQL (each a subclass of atomic and incremental stores, respectively).

The SQL persistent store is a (fully managed by the framework) SQL database, this includes:

- Creating and updating the structure, creation, modification of tables, columns, indexes, unique constraints, integration levels, etc.
- Query generation, the framework uses expressions and predicates, i.e. mathematical logic (first-order logic) to filter lookups on sets.
- Data mutation, create, update, delete.
- Data migration, this includes exporting data of data type from one persistent store to another.

Core Data use a managed object model (ManagedObjectModel), so, to update the structure of the persistent store, all you have to do is to update the model (even for the SQL persistent store) and the framework will do the rest, all unattended and automatic, no typing required a single line of code and without the need to execute any commands.

The XML store is a file.

## Initializing the Core Data Stack

The Core Data stack is a collection of framework objects that are accessed as part of the initialization of Core Data and that mediate between the objects in your application and external data stores. The Core Data stack handles all the interactions with the external data stores so that your application can focus on its business logic. The stack consists of four primary objects: the managed object context (ManagedObjectContext), the persistent store coordinator (PersistentStoreCoordinator), the managed object model (ManagedObjectModel), and the persistent container (PersistentContainer).

You initialize the Core Data stack prior to accessing your application data. The initialization of the stack prepares Core Data for data requests and the creation of data.

### PersistentContainer

The PersistentContainer handles the creation of the Core Data stack and offers access to the ManagedObjectContext as well as a number of convenience methods.

```php
<?php

try {
    $container = new PersistentContainer("DataModel");
    $container->loadPersistentStores(function (PersistentStoreDescription $description, ?Error $error): void {
        if ($error) {
            fatal_error("Unable to load persistent stores: $error");
        }
    });
} catch(Exception $exception) {
    error_log("Exception raised $exception");
}
```

### ManagedObjectModel

The ManagedObjectModel instance describes the data that is going to be accessed by the Core Data stack. During the creation of the Core Data stack, the ManagedObjectModel is loaded into memory as the first step in the creation of the stack.

### PersistentStoreCoordinator

The PersistentStoreCoordinator sits in the middle of the Core Data stack. The coordinator is responsible for realizing instances of entities that are defined inside the model. It creates new instances of the entities in the model, and it retrieves existing instances from a persistent store (PersistentStore). The persistent store can be on disk or in memory. Depending on the structure of the application, it is possible, although uncommon, to have more than one persistent store being coordinated by the PersistentStoreCoordinator.

Whereas the ManagedObjectModel defines the structure of the data, the PersistentStoreCoordinator realizes objects from the data in the persistent store and passes those objects off to the requesting ManagedObjectContext. The PersistentStoreCoordinator also verifies that the data is in a consistent state that matches the definitions in the ManagedObjectModel.

### ManagedObjectContext

The managed object context (ManagedObjectContext) is the object that your application will interact with the most, and therefore it is the one that is exposed to the rest of your application. Think of the managed object context as an intelligent scratch pad. When you fetch objects from a persistent store, you bring temporary copies onto the scratch pad where they form an object graph (or a collection of object graphs). You can then modify those objects however you like. Unless you actually save those changes, however, the persistent store remains unaltered.

All managed objects must be registered with a managed object context. You use the context to add objects to the object graph and remove objects from the object graph. The context tracks the changes you make, both to individual object's attributes and to the relationships between objects. It also ensures that if you change relationships between objects, the integrity of the object graph is maintained.

If you choose to save the changes you have made, the context ensures that your objects are in a valid state. If they are, the changes are written to the persistent store (or stores), new records are added for objects you created, and records are removed for objects you deleted.

## Creating Managed Objects

A ManagedObject instance implements the basic behavior required of a Core Data model object. The ManagedObject instance requires two elements: an entity description (an EntityDescription instance) and a managed object context (an ManagedObjectContext instance). The entity description includes the name of the entity that the object represents and its attributes and relationships. The managed object context represents a scratch pad where you create the managed objects. The context tracks changes to and relationships between objects.

```php
<?php

$employee = EntityDescription::insertNewObject("Employee", $managedObjectContext);
```

## Creating ManagedObject Subclasses

By default, Core Data returns ManagedObject instances to your application. However, it is useful to define subclasses of ManagedObject for each of the entities in your model. Specifically, when you create subclasses of ManagedObject, you can define the properties that the entity can use for code completion, and you can add convenience methods to those subclasses.

```php
<?php

namespace App;

use Sabatier\CoreData\ManagedObject;

/**
 * @property string|null $name
 */
class Employee extends ManagedObject
{
}
```

## Saving ManagedObject Instances

The creation of ManagedObject instances does not guarantee their persistence. After you create an ManagedObject instance in your managed object context, explicitly save that context to persist those changes to your persistent store.

```php
<?php

try {
    $managedObjectContext->save();
} catch (Exception $exception) {
    error_log("Exception raised $exception");
}
```

## Fetching Objects

Now that data is stored in the Core Data persistent store, you will use a FetchRequest to access that existing data. The fetching of objects from Core Data is one of the most powerful features of this framework.

### Fetching ManagedObject Instances

```php
<?php

$context = $container->viewContext;
/** @var FetchRequest<Employee> $fetchRequest */
$fetchRequest = new FetchRequest();
$fetchRequest->entity = Employee::entity();
$employees = $context->fetch($fetchRequest);
```

### Filtering Results

The real flexibility in fetching objects comes in the complexity of the fetch request. To begin with, you can add a Predicate object to the fetch request to narrow the number of objects being returned. For example, if you only want Employee objects that have a firstName of Trevor, you add the predicate directly to FetchRequest:

```php
<?php

$fetchRequest->predicate = Predicate::format("%K == %s", new ArrayClass(['firstName', new ArrayClass(['Trevor'])]));
```

A fetch request can be very complex and specific.
In a sense, this implementation works like GraphQL but you don't need to declare anything, because everything is already declared in the managed object model. In the next example Core Data will handle everything in order to return a very specific object graph.

```php
<?php

$serialization = Dictionary::dictionaryWithArray([
    'title' => AttributeType::string,
    'calendar' => [
         'title' => AttributeType::string
    ],
    'organizer' => [
        'name' => AttributeType::string
    ],
    'attendees' => [
        'name' => AttributeType::string
    ],
    'recurrenceRules' => [
        'frequency' => AttributeType::integer32,
        'interval' => AttributeType::integer32,
        'recurrenceEnd' => [
            'occurrenceCount' => AttributeType::integer32
        ]
    ],
    'alarms' => [
        'type' => AttributeType::integer16
    ],
]);
/** @var FetchRequest<Event> $fetchRequest */
$fetchRequest = new FetchRequest();
$fetchRequest->entity = Event::entity();
$fetchRequest->serialization = $serialization;
```

## Data Migration

Core Data can typically perform an automatic data migration, referred to as lightweight migration. Lightweight migration infers the migration from the differences between the source and the destination managed object models.

### Generating an Inferred Mapping Model

To perform automatic lightweight migration, Core Data needs to be able to find the source and destination managed object models at runtime. It looks for models in the bundles returned by the allBundles and allFrameworks methods of the Bundle class. Core Data then analyzes the schema changes to persistent entities and properties, and generates an inferred mapping model.

Generating an inferred mapping model requires changes to fit an obvious migration pattern, for example:

- Addition of an attribute
- Removal of an attribute
- A nonoptional attribute becoming optional
- An optional attribute becoming nonoptional, and defining a default value
- Renaming an entity or property

### Managing Changes to Entities and Properties

If you rename an entity or property, you can set the renaming identifier in the destination model to the name of the corresponding property or entity in the source model.

### Managing Changes to Relationships

Lightweight migration can also manage changes to relationships and to the type of relationship. You can add a new relationship or delete an existing relationship. You can also rename a relationship by using a renaming identifier, just like an attribute.

In addition, you can change a relationship from a to-one to a to-many, or a non-ordered to-many to an ordered (and vice versa).

### Managing Changes to Hierarchies

You can add, remove, and rename entities in the hierarchy. You can also create a new parent or child entity and move properties up and down the entity hierarchy. You can move entities out of a hierarchy. You cannot, however, merge entity hierarchies; if two existing entities do not share a common parent in the source, they cannot share a common parent in the destination.

## Persistent History

Use persistent history tracking to determine what changes have occurred in the store since the enabling of persistent history tracking.

### Consuming Relevant Store Changes

```php
<?php

// ...
$container = new PersistentContainer("DataModel");
$description = $container->persistentStoreDescriptions->first();
// turn on persistent history tracking
$description?->setOptionForKey(true, PersistentHistoryTrackingKey);
// ...
```

### Listen for Remote Changes

In the persistent container in your app's delegate, toggle the store description option for enabling remote change notifications to true.
Core Data now tracks all changes to your local store.

```php
<?php

// ...
$container = new PersistentContainer("DataModel");
// ...

// turn on remote change notifications
$description?->setOptionForKey(true, PersistentStoreRemoteChangeNotificationPostOptionKey);
// ...
```

In your view controller, add an observer to listen for remote change notifications.

```php
<?php

NotificationCenter::default()->addObserver($this, 'fetchChanges', PersistentStoreRemoteChange, $container->persistentStoreCoordinator);
```

### Request History

To request history, use the fetchHistory() type method on PersistentHistoryChangeRequest.

```php
<?php

$request = PersistentHistoryChangeRequest::fetchHistoryAfterDate(Date::distantPast());
$request->resultType = PersistentHistoryResultType::transactionsAndChanges;
if ($fetchRequest = PersistentHistoryTransaction::fetchRequest()) {
    $fetchRequest->predicate = Predicate::format("%K = %@", new ArrayClass(["author", $context->transactionAuthor]));
    $request->fetchRequest = $fetchRequest;
}
$historyResult = $context->execute($request);
assert($historyResult instanceof PersistentHistoryResult);
/** @var ArrayClass<PersistentHistoryTransaction> $history */
$history = $persistentStoreResult->result;
```

### Read History Transactions

Each transaction represents a set of changes. Iterate through the array of transactions to learn their details. The following code loops through the results of the fetchHistoryRequest to inspect the properties of each transaction.

```php
<?php

foreach ($history as $transaction) {
    // token, date and transaction number
    $token = $transaction->token;
    $timestamp = $transaction->timestamp;
    $transactionNumber = $transaction->transactionNumber;
    
    // transaction source details
    $store = $transaction->storeID;
    $bundle = $transaction->bundleID;
    $process = $transaction->processID;
    $context = $transaction->contextName ?? "unknown context";
    $author = $transaction->author ?? "unknown author";
    
    // the list of changes
    if (!($changes = $transaction->changes)) {
        continue;
    }
}
```

A transaction's changes array includes information about multiple changes. A single PersistentHistoryChange represents the insertion, update, or deletion of an object.

Iterate through a transaction's changes to identify each object that changed, the type of change that occurred, and any details about the change.

In the case of an update, the updatedProperties set includes any updated attributes and relationships. In the case of a deletion, the tombstone dictionary includes key-value pairs for any attributes marked for preservation after deletion.

```php
<?php

foreach ($changes as $change) {
    $objectID = $change->changedObjectID;
    $changeID = $change->changeID;
    $transaction = $change->transaction;
    $changeType = $change->changeType;
    switch($changeType) {
        case PersistentHistoryChangeType::update:
            if ($updatedProperties = $change->updatedProperties) {
                foreach ($updatedProperties as $updatedProperty) {
                    $name = $updatedProperty->name;
                }
            }
            break;
        case PersistentHistoryChangeType::delete:
            if ($tombstone = $change->tombstone) {
                $name = $tombstone["name"];
            }
            break;
        default:
            break
    }
}
```

### Access Attributes of a Deleted Object

After you delete an object from the store, its objectID is no longer relevant. Identify a deleted object by recording select properties in its tombstone.

In the persistent history, PersistentHistoryChangeType::delete changes include a tombstone dictionary with key-value pairs for any attributes marked for preservation after deletion.

```php
<?php

foreach ($history as $transaction) {
    //...
    foreach ($changes as $change) {
        if ($tombstone = $change->tombstone) {
            $name = $tombstone["name"];
        }
    }
}
```

### Purge History

Because persistent history tracking transactions take up space on disk, determine a clean-up strategy to remove them when they are no longer needed. Before pruning history, a single gatekeeper should ensure that your app and its clients have consumed the history they need.

```php
<?php

$context = $container->viewContext;
$purgeHistoryRequest = PersistentHistoryChangeRequest::deleteHistoryBeforeDate(Date::distantPast());
$context->execute($purgeHistoryRequest);
```

## Dependencies

The only direct dependency is [Foundation](https://github.com/dantesabatier/Foundation), also uses the great static analyzers [Psalm](https://psalm.dev/) and [PHPStan](https://phpstan.org/) and in the near future I plan to make it available via composer so you'll have a vendor folder.

## Prerequisites

The code is well documented (I wrote most of the documentation so I can implement classes and methods) but, it's best if you have some Cocoa development experience.

## Contributing

Please see [CONTRIBUTING](https://github.com/dantesabatier/coredata/blob/master/CONTRIBUTING.md) for details.

## Credits

- [Dante Sabatier](https://github.com/dantesabatier)
- [All Contributors](https://github.com/dantesabatier/coredata/contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
