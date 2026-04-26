<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 05/07/20
 * Time: 08:17
 */
namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\URL;

/**
 * A description object used to create and load a persistent store.
 */
final class PersistentStoreDescription extends ObjectClass
{
    /** @var string The type of store this description represents.
     * A string constant (such as {@see SQLStoreType}) that specifies the type of the new store see {@see PersistentStoreCoordinator}. */
    public string $type = SQLStoreType;
    /** @var string|null The name of the configuration used by this store. This displays the name of a configuration in the receiver's managed object model that will be used by the new store. The configuration can be null, in which case no other configurations are allowed. */
    public ?string $configuration = null;
    /** @var Dictionary<mixed> A dictionary containing key-value pairs that specify many settings for the persistent store. For key definitions, see {@see PersistentStoreCoordinator}. */
    private(set) Dictionary $options {
        get => $this->options ??= new Dictionary();
    }
    /** @var bool A flag that determines whether the store is added asynchronously. By default, the store is added to the {@see PersistentStoreCoordinator} synchronously on the calling thread. If this flag is set to true, the store is added asynchronously on a background queue. The default for this flag is false. */
    public bool $shouldAddStoreAsynchronously = false;
    /** @var float The connection timeout for the associated store. This is a convenience method for setting the {@see PersistentStoreTimeoutOption} on the associated store. */
    public float $timeout {
        get => $this->options[PersistentStoreTimeoutOption] ??= 8.0;
        set => $this->options[PersistentStoreTimeoutOption] = $value;
    }
    /** @var bool A flag that indicates whether this store will be read-only. This is a convenience method for setting the {@see ReadOnlyPersistentStoreOption} on the associated store. */
    public bool $isReadOnly {
        get => $this->options[ReadOnlyPersistentStoreOption] ??= false;
        set => $this->options[ReadOnlyPersistentStoreOption] = $value;
    }
    /** @var bool A flag indicating whether a mapping model should be created automatically. If this flag is set to true and the value of the {@see shouldMigrateStoreAutomatically} is true, the coordinator attempts to infer a mapping model if none can be found. The default for this flag is true. */
    public bool $shouldInferMappingModelAutomatically {
        get => $this->options[InferMappingModelAutomaticallyOption] ??= true;
        set => $this->options[InferMappingModelAutomaticallyOption] = $value;
    }
    /** @var bool A flag indicating whether the associated persistent store should be migrated automatically. If this is set to false and the store is out of sync, attempting to load the store produces an error. If this is set to true and the store is out of sync, attempting to load the store causes Core Data to attempt a migration. This flag is set to true by default. */
    public bool $shouldMigrateStoreAutomatically {
        get => $this->options[MigratePersistentStoresAutomaticallyOption] ??= true;
        set => $this->options[MigratePersistentStoresAutomaticallyOption] = $value;
    }

    /**
     * Initializes the receiver with a URL for the store.
     * @param URL $url Location for the store.
     */
    public function __construct(public URL $url)
    {
    }

    /**
     * Sets an option on the store.
     *
     * If a value was previously set for the given option, that value is replaced with the given value.
     * Note that the keys are case-sensitive. For a list of the available options, see {@see PersistentStoreCoordinator}.
     * @param mixed $option The value to be set for an option on the store.
     * @param string $key The key of the value to be set for an option on the store.
     */
    public function setOptionForKey(mixed $option, string $key): void
    {
        $this->options[$key] = $option;
    }
}
