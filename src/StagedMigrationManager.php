<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\ObjectClass;
use const Sabatier\Foundation\kCFBundleNameKey;

/**
 * An object that handles the migration event loop and provides access to the migrating persistent store.
 *
 * A staged migration manager contains the individual stages of a migration and applies those stages, in the order you specify when that migration runs. The manager handles the migration's event loop and provides access to the migrating store through its {@see $container} property. Stages can be custom, which enables you to perform tasks immediately before and after a stage runs, or lightweight, which supplements custom stages with those that Core Data can invoke automatically because they’re already compatible with lightweight migrations.
 *
 * Use {@see PersistentStoreStagedMigrationManagerOptionKey} to include an instance of StagedMigrationManager in your persistent store's option dictionary, as the following example shows:
 * <code>
 *     $manager = new StagedMigrationManager($stages);
 *     $options = new Dictionary([MigratePersistentStoresAutomaticallyOption => true, InferMappingModelAutomaticallyOption => true,PersistentStoreStagedMigrationManagerOptionKey => $manager]);
 *     $store = $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, $storeURL, $options);
 * </code>
 */
class StagedMigrationManager extends ObjectClass
{
    /** @var PersistentContainer|null The container that provides access to the migrating persistent store. */
    private(set) ?PersistentContainer $container {
        get => $this->container ??= $this->createPersistentContainer();
    }

    /**
     * Creates a migration manager with the specified stages.
     *
     * @param ArrayClass<MigrationStage> $stages The array of migration stages to execute.
     */
    public function __construct(public readonly ArrayClass $stages)
    {
    }

    private function createPersistentContainer(): ?PersistentContainer
    {
        $bundle = Bundle::main();
        if (!($name = $bundle->object(kCFBundleNameKey))) {
            return null;
        }
        if (!($url = $bundle->url($name, "plist"))) {
            return null;
        }
        if (!FileManager::default()->fileExists($url->path)) {
            return null;
        }
        return new PersistentContainer($name, new ManagedObjectModel($url));
    }
}
