<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\URL;

/**
 * An object that describes a specific version of an object model.
 */
final class ManagedObjectModelReference extends ObjectClass
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

    /**
     * Creates an object model reference for the model at the specified file URL.
     *
     * To determine an object model’s version checksum, use its {@see ManagedObjectModel::$versionChecksum} property.
     *
     * @param URL $url The on-disk location of the managed object model.
     * @param string $versionChecksum The checksum of the object model’s version.
     * @return ManagedObjectModelReference A reference to the managed object model.
     */
    public static function fileURL(URL $url, string $versionChecksum): ManagedObjectModelReference
    {
        return new ManagedObjectModelReference(new ManagedObjectModel($url), $versionChecksum);
    }

    /**
     * Creates an object model reference for the named model in the specified bundle.
     *
     * To determine an object model’s version checksum, use its {@see ManagedObjectModel::$versionChecksum} property.
     *
     * @param string $name The name of the managed object model in the specified bundle.
     * @param Bundle|null $bundle The bundle to search.
     * @param string $versionChecksum The checksum of the object model’s version.
     * @return ManagedObjectModelReference A reference to the managed object model.
     */
    public static function name(string $name, ?Bundle $bundle, string $versionChecksum): ManagedObjectModelReference
    {
        return new ManagedObjectModelReference(new ManagedObjectModel($bundle?->url($name, "mom")), $versionChecksum);
    }
}
