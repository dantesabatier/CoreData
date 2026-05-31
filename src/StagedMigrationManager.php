<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\ObjectClass;

/**
 * An object that handles the migration event loop and provides access to the migrating persistent store.
 *
 * A staged migration manager contains the individual stages of a migration and applies those stages, in the order you specify when that migration runs. The manager handles the migration’s event loop and provides access to the migrating store through its {@see $container} property. Stages can be custom, which enables you to perform tasks immediately before and after a stage runs, or lightweight, which supplements custom stages with those that Core Data can invoke automatically because they’re already compatible with lightweight migrations.
 *
 * Use {@see PersistentStoreStagedMigrationManagerOptionKey} to include an instance of StagedMigrationManager in your persistent store’s option dictionary, as the following example shows:
 * <code>
 *     $manager = new StagedMigrationManager($stages);
 *     $options = new Dictionary([MigratePersistentStoresAutomaticallyOption => true, InferMappingModelAutomaticallyOption => true,PersistentStoreStagedMigrationManagerOptionKey => $manager]);
 *     $store = $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $storeURL, $options);
 * </code>
 */
final class StagedMigrationManager extends ObjectClass
{
    /** @var PersistentContainer|null The container that provides access to the migrating persistent store. */
    public ?PersistentContainer $container = null;

    /**
     * Creates a migration manager with the specified stages.
     *
     * @param ArrayClass<MigrationStage> $stages The array of migration stages to execute.
     */
    public function __construct(public readonly ArrayClass $stages)
    {
    }

    /**
     * @internal
     */
    public function findCurrentMigrationStageFromModelChecksum(string $checksum): int
    {
        foreach ($this->stages as $index => $stage) {
            if ($stage instanceof LightweightMigrationStage) {
                if ($stage->versionChecksums->containsElement($checksum)) {
                    return $index;
                }
            } elseif ($stage instanceof CustomMigrationStage) {
                if ($stage->currentModel->versionChecksum === $checksum || $stage->nextModel->versionChecksum === $checksum) {
                    return $index;
                }
            }
        }
        return -1;
    }

    /**
     * @internal
     */
    public function shouldAttemptStagedMigrationWithStoreModelVersionChecksum(string $storeChecksum, string $coordinatorChecksum, ?Error &$error): bool
    {
        if ($storeChecksum === $coordinatorChecksum) {
            return false;
        }
        if (!$this->validateStages($error)) {
            return false;
        }
        $storeStageIndex = $this->findCurrentMigrationStageFromModelChecksum($storeChecksum);
        if ($storeStageIndex === -1) {
            $error = new Error(CoreDataErrorDomain, CoreDataError);
            return false;
        }
        return true;
    }

    /**
     * @internal
     */
    public function validateStages(?Error &$error): bool
    {
        if ($this->stages->isEmpty) {
            $error = new Error(CoreDataErrorDomain, CoreDataError);
            return false;
        }
        $previousNextChecksum = null;
        foreach ($this->stages as $stage) {
            if ($stage instanceof CustomMigrationStage) {
                if ($previousNextChecksum !== null && $stage->currentModel->versionChecksum !== $previousNextChecksum) {
                    $error = new Error(CoreDataErrorDomain, CoreDataError);
                    return false;
                }
                $previousNextChecksum = $stage->nextModel->versionChecksum;
            } elseif ($stage instanceof LightweightMigrationStage) {
                if ($stage->versionChecksums->isEmpty) {
                    $error = new Error(CoreDataErrorDomain, CoreDataError);
                    return false;
                }
                if ($previousNextChecksum !== null && $stage->versionChecksums->first !== $previousNextChecksum) {
                    $error = new Error(CoreDataErrorDomain, CoreDataError);
                    return false;
                }
                $previousNextChecksum = $stage->versionChecksums->last;
            }
        }
        return true;
    }
}
