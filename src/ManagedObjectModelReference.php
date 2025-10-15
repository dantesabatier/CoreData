<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ObjectClass;

/**
 * An object that describes a specific version of an object model.
 */
class ManagedObjectModelReference extends ObjectClass
{
    /**
     * Creates an object model reference for the specified model.
     *
     * @param ManagedObjectModel $resolvedModel The resolved object model.
     * @param string $versionChecksum The version checksum of the resolved model.
     */
    public function __construct(public readonly ManagedObjectModel $resolvedModel, public readonly string $versionChecksum)
    {
    }
}
