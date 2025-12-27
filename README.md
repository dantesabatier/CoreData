# Sabatier CoreData

**Sabatier CoreData** is a robust object-graph management and persistence framework for PHP 8.5+, heavily inspired by Apple's Core Data. It allows developers to manage the lifecycle of model objects, handle complex relationships, and persist data without writing raw SQL, all while maintaining a highly optimized memory footprint.

## ✨ Key Features

- **Managed Object Context (MOC)**: A powerful "scratchpad" for your objects. It tracks insertions, updates, and deletions, providing an internally consistent view of your data.
- **Advanced Faulting**: Implements the "faulting" pattern via `FaultHandler`. Objects and relationships are only fully realized when accessed, minimizing I/O and memory usage.
- **Batch Faulting**: Efficiently handle thousands of records with `BatchFaultingArray`. It supports batch sizes, allowing the framework to fetch object IDs and only inflate full objects as you iterate.
- **Relationship Management**: Full support for To-One and To-Many relationships, including automated inverse relationship handling and delete rules (Nullify, Cascade, Deny).
- **Concurrency Support**: Designed with concurrency types (`mainQueueConcurrencyType`, `privateQueueConcurrencyType`) and queue-based execution via `performBlock()`.
- **Change Tracking & Undo**: Integrated with `UndoManager` and KVO-style (Key-Value Observing) notifications to monitor object graph changes in real-time.
- **Constraint Resolution**: Built-in support for merge policies and unique constraint conflict resolution.

## 🛠 Requirements

- **PHP 8.5** or higher (leveraging property hooks, readonly properties, and advanced type systems).
- **Foundation Library**: Depends on `sabatier/foundation`.

## 📦 Installation

```bash
composer require sabatier/foundation:dev-master
```

## 🚀 Quick Start
### Basic Fetching and Saving

```php
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\FetchRequest;

// 1. Initialize the context
$context = new ManagedObjectContext();
$context->persistentStoreCoordinator = $myCoordinator;

// 2. Prepare a fetch request with batching
$fetchRequest = new FetchRequest();
$fetchRequest->entity = $model->entitiesByName['Employee'];
$fetchRequest->fetchBatchSize = 50;

// 3. Execute fetch (returns a BatchFaultingArray)
$employees = $context->fetch($fetchRequest);

foreach ($employees as $employee) {
    echo $employee->lastName;
    // Objects are automatically turned from faults into realized objects here
    $employee->lastAccessDate = now(); 
}

// 4. Persist changes
if ($context->hasChanges) {
    try {
        $context->save();
    } catch (Exception $e) {
        // Handle validation or merge conflicts
    }
}
```
## 🏗 Architecture & Core Components

The framework is designed as a multi-layered stack that separates the object graph from the physical storage, allowing for high flexibility and performance.

### 1. The Object Graph Layer
- **`ManagedObject`**: The base class for all your data entities. It handles property observation (KVO) and state management (Clean, New, Updated, Deleted).
- **`ManagedObjectContext`**: Your primary interface for data manipulation. It acts as an in-memory "scratchpad" where you can create, fetch, and modify objects before committing changes.
- **`UndoManager`**: Integrated directly into the context, allowing you to roll back or redo complex object graph changes effortlessly.

### 2. The Coordination Layer
- **`PersistentStoreCoordinator`**: The "hub" of the stack. It mediates between the high-level `ManagedObjectContext` and the low-level `PersistentStore`. It handles the mapping between your PHP objects and the storage schema.
- **`ManagedObjectModel`**: A collection of `EntityDescription` objects that define the structure, properties, and relationships of your data.

### 3. The Persistence Layer
- **`PersistentStore`**: The abstract base class for all storage types.
- **`IncrementalStore`**: A specialized abstract subclass designed for stores that load and save data in chunks (like SQL). It uses `IncrementalStoreNode` to pass data between the store and the context.
- **`SQLCore`**: The primary implementation for relational databases, transforming `FetchRequest` objects into optimized SQL queries.

### 4. Performance Mechanisms
- **`FaultHandler`**: Automatically manages "Faulting." It keeps the application's memory usage low by creating "hollow" objects that only load their full data when a property is actually accessed.
- **`BatchFaultingArray`**: A specialized collection that enables seamless iteration over massive result sets. It fetches data in batches, ensuring that only the necessary objects are in memory at any given time.

## License

This project is licensed under the MIT License. See the `LICENSE.md` file for details.
