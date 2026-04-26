<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

/**
 * An object that describes a series of models suitable for lightweight migration.
 *
 * Use LightweightMigrationStage when you have a series of models to migrate, and those models are compatible with lightweight migrations. Instances of this class supplement your custom migration stages and help maintain a consistent stage order for the entire migration.
 */
final class LightweightMigrationStage extends MigrationStage
{
    /**
     * @param ArrayClass<string> $versionChecksums The array of version checksums.
     */
    public function __construct(public readonly ArrayClass $versionChecksums)
    {
    }
}
