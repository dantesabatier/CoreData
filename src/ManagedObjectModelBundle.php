<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\PropertyListSerialization;
use Sabatier\Foundation\URL;

/**
 * A package holding one archived managed object model per version of a model, alongside the version
 * information that names the current one.
 *
 * Migrating between two versions of a model needs both to exist at once, which a single model file
 * cannot provide. The version information is what tells the versions apart; a package assembled by
 * hand may carry none, in which case the version named after the package is the current one.
 *
 * A URL that is not a package yields a bundle with no versions at all, so a caller can ask without
 * having to know which of the two layouts it is looking at.
 * @internal
 */
final class ManagedObjectModelBundle
{
    /** @var Bundle The package as a bundle, so its contents are enumerated the way any bundle's are. */
    private Bundle $bundle {
        get => $this->bundle ??= Bundle::bundleWithURL($this->url);
    }
    /** @var Dictionary<mixed>|null The version information of the package, or null when it carries none. */
    private ?Dictionary $versionInfo {
        get {
            if ($this->isVersionInfoResolved) {
                return $this->versionInfo;
            }
            $this->isVersionInfoResolved = true;
            /** @var Dictionary<mixed>|null $versionInfo */
            $versionInfo = $this->isPackage ? PropertyListSerialization::propertyListWithURL($this->versionInfoURL) : null;
            return $this->versionInfo = $versionInfo;
        }
    }
    /** @var bool Whether the URL addresses a package at all. Only the extension is examined; whether the package holds anything is answered by the versions themselves. */
    private bool $isPackage {
        get => $this->isPackage ??= $this->url->pathExtension === ManagedObjectModelBundleFileExtension;
    }
    /** @var URL The location of the package's version information. */
    public URL $versionInfoURL {
        get => $this->versionInfoURL ??= $this->url->appendingPathComponent(ManagedObjectModelVersionInfoFileName)->appendingPathExtension("plist");
    }
    /** @var ArrayClass<string> The names of every version in the package, in the order the package lists them. */
    public ArrayClass $modelVersions {
        get => $this->modelVersions ??= $this->isPackage ? ($this->bundle->urls(ManagedObjectModelFileExtension) ?? new ArrayClass())->map(fn(URL $url): string => $url->deletingPathExtension()->lastPathComponent) : new ArrayClass();
    }
    /** @var Dictionary<string> The version checksum of every version the version information records, keyed by version name. A package carrying no version information records none: a checksum is only known by opening the model. */
    public Dictionary $versionChecksums {
        get {
            if (isset($this->versionChecksums)) {
                return $this->versionChecksums;
            }
            /** @var Dictionary<string>|null $versionChecksums */
            $versionChecksums = $this->versionInfo?->valueForKey(ManagedObjectModelVersionHashesKey);
            return $this->versionChecksums = $versionChecksums ?? new Dictionary();
        }
    }
    /** @var string|null The name of the version to load, or null when the package holds none. */
    public ?string $currentVersion {
        get {
            if ($this->isCurrentVersionResolved) {
                return $this->currentVersion;
            }
            $this->isCurrentVersionResolved = true;
            if (!$this->isPackage) {
                return $this->currentVersion = null;
            }
            /** @var string $name */
            $name = $this->versionInfo?->valueForKey(ManagedObjectModelCurrentVersionNameKey) ?? $this->url->deletingPathExtension()->lastPathComponent;
            return $this->currentVersion = $this->modelVersions->containsElement($name) ? $name : null;
        }
    }
    /** @var URL|null The location of the model to load, or null when the package holds no such version. */
    public ?URL $currentVersionURL {
        get => ($name = $this->currentVersion) === null ? null : $this->urlForModelVersionNamed($name);
    }
    private bool $isVersionInfoResolved = false;
    private bool $isCurrentVersionResolved = false;

    /**
     * @param URL $url The location of the model bundle.
     */
    public function __construct(public readonly URL $url)
    {
    }

    /**
     * Returns the location of the named version, whether or not the package holds it.
     * @param string $name The name of a version.
     */
    public function urlForModelVersionNamed(string $name): URL
    {
        return $this->url->appendingPathComponent($name)->appendingPathExtension(ManagedObjectModelFileExtension);
    }

    /**
     * Returns the location of the version a given checksum identifies, or null when the package holds no such version.
     *
     * Only the version information relates a checksum to a version, so a package carrying none cannot answer this.
     * @param string $versionChecksum The checksum of the version to locate.
     * @throws Exception
     */
    public function urlForModelVersionWithChecksum(string $versionChecksum): ?URL
    {
        $versionChecksums = $this->versionChecksums;
        $name = $versionChecksums->keys->first(fn(string $versionName): bool => $versionChecksums[$versionName] === $versionChecksum);
        if ($name === null || !$this->modelVersions->containsElement($name)) {
            return null;
        }
        return $this->urlForModelVersionNamed($name);
    }
}
