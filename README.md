# Core Data

An open source implementation of *[Core Data](https://developer.apple.com/documentation/coredata)*, a wonderful, very complex and beautifully designed framework, responsible for many bundled applications, services and frameworks in macOS and iOS that I created using mostly my intuition and experience developing Cocoa applications.

## What is Core Data?

Core Data is an *[Object graph](https://en.wikipedia.org/wiki/Object_graph)* and *[Persistence framework](https://en.wikipedia.org/wiki/Persistence_framework)* that enables the organization and manipulation of data from an entity-attribute relational model.

Core Data provides two abstract types of persistent stores:

- Atomic, intended to handle data sets that can be expressed in memory, favors simplicity over performance.
- Incremental, to manage large and/or shared data sets.

Core Data also provides the implementation of two specific persistent store types, XML and SQL (each a subclass of atomic and incremental stores, respectively).

Core Data uses a managed object model (ManagedObjectModel), so, to update the structure of the persistent store, all you have to do is to update the model, and the framework will do the rest, all unattended and automatic, no typing required a single line of code and without the need to execute any command.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
