<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

/**
 * A protocol that defines the interface for section objects vended by a fetched results' controller.
 */
final class FetchedResultsSectionInfo
{
    /** @var int The number of objects (rows) in the section. */
    public int $numberOfObjects {
        get => $this->objects->count;
    }

    /**
     * The class cannot be readonly because PHP forbids a hooked property there, so each promoted
     * property carries its own readonly instead.
     *
     * @param string $name The name of the section.
     * @param ArrayClass<mixed> $objects The array of objects in the section.
     * @param string|null $indexTitle The index title of the section.
     */
    public function __construct(public readonly string $name, public readonly ArrayClass $objects, public readonly ?string $indexTitle = null)
    {
    }
}
