<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

/**
 * A protocol that defines the interface for section objects vended by a fetched results' controller.
 */
class FetchedResultsSectionInfo
{
    /** @var int The number of objects (rows) in the section. */
    public readonly int $numberOfObjects;

    /**
     * @param string $name The name of the section.
     * @param ArrayClass $objects The array of objects in the section.
     * @param string|null $indexTitle The index title of the section.
     */
    public function __construct(public string $name, public readonly ArrayClass $objects = new ArrayClass(), public readonly ?string $indexTitle = null)
    {
        $this->numberOfObjects = $this->objects->count();
    }
}
