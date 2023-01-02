<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

/**
 * A protocol that defines the interface for section objects vended by a fetched results controller.
 */
abstract class FetchedResultsSectionInfo
{
    /** @var int The number of objects (rows) in the section. */
    public int $numberOfObjects = 0;
    /** @var ArrayClass<mixed> The array of objects in the section. */
    public ?ArrayClass $objects = null;
    /** @var string The name of the section. */
    public string $name = "";
    /** @var string|null The index title of the section. */
    public ?string $indexTitle = null;
}
