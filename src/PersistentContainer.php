<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 05/07/20
 * Time: 08:16
 */

namespace Sabatier\CoreData;

use Closure;
use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\SearchPathDirectory;
use Sabatier\Foundation\SearchPathDomainMask;
use Sabatier\Foundation\URL;

/**
 * A container that encapsulates the Core Data stack in your app.
 *
 * PersistentContainer simplifies the creation and management of the Core Data stack by handling the creation of the managed object model ({@see ManagedObjectModel}), persistent store coordinator ({@see PersistentStoreCoordinator}), and the managed object context ({@see ManagedObjectContext}).
 */
final class PersistentContainer extends ObjectClass
{
    /** @var string The container’s name. This property is passed in as part of the initialization of the persistent container. This name is used to locate the {@see ManagedObjectModel} (if the {@see ManagedObjectModel} object is not passed in as part of the initialization) and is used to name the persistent store. */
    public readonly string $name;
    /** @var ManagedObjectModel The model associated with this persistent container. This property contains a reference to the {@see ManagedObjectModel} object associated with this persistent container. */
    public readonly ManagedObjectModel $managedObjectModel;
    /** @var PersistentStoreCoordinator The persistent store coordinator associated with this persistent container. When the persistent container is initialized, it creates a persistent store coordinator as part of that initialization. That persistent store coordinator is referenced in this property. */
    public readonly PersistentStoreCoordinator $persistentStoreCoordinator;
    /** @var ArrayClass<PersistentStoreDescription> The persistent store descriptions used to create the persistent stores referenced by this persistent container. If you want to override the type (or types) of persistent store(s) used by the persistent container, you can set this property with an array of {@see PersistentStoreDescription} objects. If you will be configuring custom persistent store descriptions, you must set this property before calling {@see loadPersistentStores()}. */
    public ArrayClass $persistentStoreDescriptions;
    /** @var ManagedObjectContext The managed object context associated with the main queue. This property contains a reference to the {@see ManagedObjectContext} that is created and owned by the persistent container which is associated with the main queue of the application. This context is created automatically as part of the initialization of the persistent container. This context is associated directly with the {@see PersistentStoreCoordinator} and is non-generational by default. */
    public readonly ManagedObjectContext $viewContext;

    /**
     * Initializes a persistent container with the given name and model.
     *
     * By default, the provided name value of the container is used as the name of the persistent store associated with the container.
     * Passing in the ManagedObjectModel object overrides the lookup of the model by the provided name value.
     * @param string $name The name used by the persistent container.
     * @param ManagedObjectModel|null $managedObjectModel The managed object model to be used by the persistent container.
     */
    public function __construct(string $name, ?ManagedObjectModel $managedObjectModel = null)
    {
        $bundle = $this->isSubclass(PersistentContainer::class) ? Bundle::bundleForClass(static::class) : Bundle::main();
        $this->name = $name;
        $this->managedObjectModel = $managedObjectModel ?? new ManagedObjectModel($bundle->url($this->name, "plist"));
        $this->persistentStoreCoordinator = new PersistentStoreCoordinator($this->managedObjectModel);
        $this->viewContext = new ManagedObjectContext();
        $this->viewContext->persistentStoreCoordinator = $this->persistentStoreCoordinator;
        /** @var ArrayClass<string> $types */
        $types = $bundle->infoDictionary?->valueForKeyPath("CFBundleDocumentTypes.CFBundleTypeName") ?? new ArrayClass([SQLStoreType]);
        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $this->persistentStoreDescriptions = $types->compactMap(function (string $type): ?PersistentStoreDescription {
                if (!($url = match ($type) {
                    SQLStoreType => new URL("sql://$this->name"),
                    XMLStoreType => static::defaultDirectoryURL()->appendingPathComponent($this->name)->appendingPathComponent($this->name)->appendingPathExtension("xml"),
                    default => null
                })) {
                    return null;
                }
                if ($url->isFileURL) {
                    $directoryURL = $url->deletingLastPathComponent();
                    if (!FileManager::default()->fileExists($directoryURL->path)) {
                        try {
                            FileManager::default()->createDirectory($directoryURL, true);
                        } catch (Exception) {
                            return null;
                        }
                    }
                }
                $persistentStoreDescription = new PersistentStoreDescription($url);
                $persistentStoreDescription->type = $type;
                $persistentStoreDescription->configuration = $this->name;
                $persistentStoreDescription->shouldInferMappingModelAutomatically = true;
                $persistentStoreDescription->shouldMigrateStoreAutomatically = true;
                if ($type === XMLStoreType) {
                    $persistentStoreDescription->setOptionForKey(true, ValidateXMLStoreOption);
                }
                return $persistentStoreDescription;
            });
    }

    /**
     * Loads the persistent stores.
     * @param Closure(PersistentStoreDescription, Error|null): void $completion Once the loading of the persistent stores has completed, this block will be executed on the calling thread.
     * Once the persistent container has been initialized, you need to execute {@see loadPersistentStores()} to instruct the container to load the persistent stores and complete the creation of the Core Data stack.
     * Once the completion handler has fired, the stack is fully initialized and is ready for use.
     * The completion handler will be called once for each persistent store that is created.
     * If there is an error in the loading of the persistent stores, the {@see Error} value will be populated.
     */
    public function loadPersistentStores(Closure $completion): void
    {
        foreach ($this->persistentStoreDescriptions as $persistentStoreDescription) {
            $this->persistentStoreCoordinator->addPersistentStoreWithDescription($persistentStoreDescription, function (PersistentStoreDescription $description, ?Error $error) use ($completion): void {
                $completion($description, $error);
            });
        }
    }

    /**
     * Creates a private managed object context.
     *
     * Invoking this method causes the persistent container to create and return a new {@see ManagedObjectContext} with the concurrencyType set to {@see ManagedObjectContextConcurrencyType::private}.
     * This new context will be associated with the {@see PersistentStoreCoordinator} directly and is set to consume {@see ManagedObjectContext::didSaveObjectsNotification} broadcasts automatically.
     * @return ManagedObjectContext A newly created private managed object context.
     */
    public function newBackgroundContext(): ManagedObjectContext
    {
        $context = new ManagedObjectContext(ManagedObjectContextConcurrencyType::privateQueueConcurrencyType);
        $context->persistentStoreCoordinator = $this->persistentStoreCoordinator;
        $context->parent = $this->viewContext;
        return $context;
    }

    /**
     * Causes the persistent container to execute the block against a new private queue context.
     *
     * Each time this method is invoked, the persistent container creates a new {@see ManagedObjectContext} with the concurrencyType set to {@see ManagedObjectContextConcurrencyType::privateQueueConcurrencyType}.
     * The persistent container then executes the passed in block against that newly created context on the context's private queue.
     * @param Closure(ManagedObjectContext): void $task A block that is executed by the persistent container against a newly created private context.
     * The private context is passed into the block as part of the execution of the block.
     */
    public function performBackgroundTask(Closure $task): void
    {
        $backgroundContext = $this->newBackgroundContext();
        $backgroundContext->performBlock(function () use ($backgroundContext, $task): void {
            $task($backgroundContext);
        });
    }

    /**
     * Creates the default directory for the persistent stores on the current platform.
     * @return URL A URL that references the directory in which the persistent store(s) will be located or are currently located.
     * @throws Exception
     */
    public static function defaultDirectoryURL(): URL
    {
        return FileManager::default()->url(SearchPathDirectory::applicationSupportDirectory, SearchPathDomainMask::local, null, true);
    }
}
