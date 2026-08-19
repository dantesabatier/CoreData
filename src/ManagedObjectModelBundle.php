<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\PropertyListSerialization;
use Sabatier\Foundation\URL;

/**
 * A package holding one archived managed object model per version of a model, alongside the version
 * information that names the current one.
 *
 * Migrating between two versions of a model needs both to exist at once, which a single model file
 * cannot provide. The version information is what distinguishes the versions from one another; a
 * package assembled by hand may carry none, in which case the version named after the package is the
 * current one.
 * @internal
 */
final class ManagedObjectModelBundle
{
    /** @var Dictionary<mixed>|null The version information of the bundle, or null when it carries none. */
    private ?Dictionary $versionInfo {
        get {
            if ($this->isVersionInfoResolved) {
                return $this->versionInfo;
            }
            $this->isVersionInfoResolved = true;
            /** @var Dictionary<mixed>|null $versionInfo */
            $versionInfo = PropertyListSerialization::propertyListWithURL($this->versionInfoURL);
            return $this->versionInfo = $versionInfo;
        }
    }
    /** @var URL The location of the bundle's version information. */
    public URL $versionInfoURL {
        get => $this->versionInfoURL ??= $this->url->appendingPathComponent(ManagedObjectModelVersionInfoFileName)->appendingPathExtension("plist");
    }
    /** @var string The name a version carries when the bundle names none: the bundle's own. */
    private string $defaultVersionName {
        get => $this->defaultVersionName ??= $this->url->deletingPathExtension()->lastPathComponent;
    }
    /** @var URL|null The location of the model to load, or null when the bundle holds no such version. */
    public ?URL $currentVersionURL {
        /**
         * @throws Exception
         */
        get {
            if ($this->isCurrentVersionURLResolved) {
                return $this->currentVersionURL;
            }
            $this->isCurrentVersionURLResolved = true;
            /** @var string|null $name */
            $name = $this->versionInfo?->valueForKey(ManagedObjectModelCurrentVersionNameKey);
            return $this->currentVersionURL = $this->versionURLNamed($name ?? $this->defaultVersionName);
        }
    }
    private bool $isVersionInfoResolved = false;
    private bool $isCurrentVersionURLResolved = false;

    /**
     * @param URL $url The location of the model bundle.
     */
    public function __construct(public readonly URL $url)
    {
    }

    /**
     * Returns whether a given URL can be initialized as a model bundle. Only the extension is examined: whether the package holds a version to load is answered by {$currentVersionURL}.
     */
    public static function canInitWithURL(URL $url): bool
    {
        return $url->pathExtension === ManagedObjectModelBundleFileExtension;
    }

    /**
     * Returns the location of the version a given checksum identifies, or null when the bundle holds no such version.
     *
     * Only the version information relates a checksum to a version, so a bundle carrying none cannot answer this.
     * @param string $versionChecksum The checksum of the version to locate.
     * @throws Exception
     */
    public function versionURL(string $versionChecksum): ?URL
    {
        /** @var Dictionary<string>|null $versionHashesByName */
        $versionHashesByName = $this->versionInfo?->valueForKey(ManagedObjectModelVersionHashesKey);
        if (!$versionHashesByName) {
            return null;
        }
        $name = $versionHashesByName->keys->first(fn(string $versionName): bool => $versionHashesByName[$versionName] === $versionChecksum);
        return $name === null ? null : $this->versionURLNamed($name);
    }

    /**
     * @throws Exception
     */
    private function versionURLNamed(string $name): ?URL
    {
        $versionURL = $this->url->appendingPathComponent($name)->appendingPathExtension(ManagedObjectModelFileExtension);
        return FileManager::default()->fileExists($versionURL->path) ? $versionURL : null;
    }
}
