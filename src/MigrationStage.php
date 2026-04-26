<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ObjectClass;

/**
 * An abstract base class for describing an individual stage of a migration.
 */
abstract class MigrationStage extends ObjectClass
{
    /** @var string The textual description of the migration stage’s purpose. */
    public string $label;
}
