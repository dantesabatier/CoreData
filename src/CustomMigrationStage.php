<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Closure;

/**
 * An object that enables you to participate in the migration between two versions of the same model.
 * Use CustomMigrationStage when you have two versions of a model that Core Data can’t automatically migrate. Custom migration stages enable you to participate in the migration process by assigning handlers that the stage invokes before and after it runs. The handlers provide an opportunity to prepare the persistent store’s data for the upcoming changes before the stage runs and perform any cleanup tasks afterward.
 *
 * For example, to support a migration that changes an optional attribute to be nonoptional, you might assign a handler to the stage’s willMigrateHandler property that sets any nil instances of that attribute to a default value, thereby ensuring the migration succeeds. To access the store you’re migrating, use the container property of the migration manager that Core Data provides to every handler.
 */
final class CustomMigrationStage extends MigrationStage
{
    /** @var Closure(StagedMigrationManager, CustomMigrationStage): void|null The handler to execute before the stage runs. Use this handler to prepare the persistent store’s data for the pending migration. Access the store using the container property of the handler’s migrationManager parameter. */
    public ?Closure $willMigrateHandler = null;
    /** @var Closure(StagedMigrationManager, CustomMigrationStage): void|null The handler to execute after the stage runs. Use this handler to perform any cleanup tasks on the persistent store’s data after the migration has run. Access the store using the container property of the handler’s migrationManager parameter. */
    public ?Closure $didMigrateHandler = null;

    /**
     * Creates a custom migration stage with the specified source and destination model references.
     *
     * @param ManagedObjectModelReference $currentModel The reference that represents the migration’s source model.
     * @param ManagedObjectModelReference $nextModel The reference that represents the migration’s destination model.
     */
    public function __construct(public readonly ManagedObjectModelReference $currentModel, public readonly ManagedObjectModelReference $nextModel)
    {
    }
}
