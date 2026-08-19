<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\ManagedObjectModelBundle;
use Sabatier\CoreData\ManagedObjectModelReference;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\PropertyListSerialization;
use Sabatier\Foundation\URL;
use Throwable;
use const Sabatier\CoreData\ManagedObjectModelBundleFileExtension;
use const Sabatier\CoreData\ManagedObjectModelCurrentVersionNameKey;
use const Sabatier\CoreData\ManagedObjectModelFileExtension;
use const Sabatier\CoreData\ManagedObjectModelVersionHashesKey;

/**
 * Loading a model out of a model bundle.
 *
 * A project keeps one archived model per version, and the migration between two of them needs both
 * to exist at once. The bundle is where they live: a package whose version information names the one
 * to load. A model file on its own keeps working — that is what every project has today.
 */
final class ManagedObjectModelBundleTest extends TestCase
{
    private string $resources;

    protected function setUp(): void
    {
        $this->resources = sys_get_temp_dir() . "/" . uniqid("momd", true) . "/Resources";
        mkdir($this->resources, 0777, true);
    }

    protected function tearDown(): void
    {
        try {
            FileManager::default()->removeItem(URL::fileURL(dirname($this->resources)));
        } catch (Throwable) {
        }
    }

    private static function model(string $attributeName): ManagedObjectModel
    {
        $attribute = new AttributeDescription();
        $attribute->name = $attributeName;
        $attribute->type = AttributeType::string;
        $attribute->isOptional = true;

        $entity = new EntityDescription();
        $entity->name = "Recipe";
        $entity->properties = new ArrayClass([$attribute]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private function url(string $relativePath): URL
    {
        return URL::fileURL($this->resources . "/" . $relativePath);
    }

    private function writeModel(string $relativePath, ManagedObjectModel $model): void
    {
        file_put_contents($this->resources . "/" . $relativePath, KeyedArchiver::archivedData($model));
    }

    private function makeBundle(string $name): string
    {
        $path = $this->resources . "/" . $name . "." . ManagedObjectModelBundleFileExtension;
        mkdir($path, 0777, true);
        return $name . "." . ManagedObjectModelBundleFileExtension;
    }

    /**
     * @param Dictionary<mixed> $versionInfo
     */
    private function writeVersionInfo(string $bundle, Dictionary $versionInfo): void
    {
        file_put_contents($this->resources . "/" . $bundle . "/VersionInfo.plist", PropertyListSerialization::data($versionInfo));
    }

    public function testAModelFileOnItsOwnStillLoads(): void
    {
        $this->writeModel("Recipes." . ManagedObjectModelFileExtension, self::model("directions"));

        $model = new ManagedObjectModel($this->url("Recipes." . ManagedObjectModelFileExtension));

        $this->assertNotNull($model->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    public function testTheBundleLoadsTheVersionItsInformationNames(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("directions"));
        $this->writeModel($bundle . "/Recipes 2.mom", self::model("instructions"));
        $this->writeVersionInfo($bundle, new Dictionary([ManagedObjectModelCurrentVersionNameKey => "Recipes 2"]));

        $model = new ManagedObjectModel($this->url($bundle));

        $this->assertNotNull($model->entitiesByName["Recipe"]?->attributesByName["instructions"], "the named version must be the one loaded");
        $this->assertNull($model->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    /** A bundle assembled by hand may carry no version information, the way an unvalidated bundle does elsewhere. */
    public function testABundleWithoutVersionInformationFallsBackToItsOwnName(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("directions"));

        $model = new ManagedObjectModel($this->url($bundle));

        $this->assertNotNull($model->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    public function testAVersionIsResolvedByItsChecksum(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $first = self::model("directions");
        $second = self::model("instructions");
        $this->writeModel($bundle . "/Recipes.mom", $first);
        $this->writeModel($bundle . "/Recipes 2.mom", $second);
        $this->writeVersionInfo($bundle, new Dictionary([
            ManagedObjectModelCurrentVersionNameKey => "Recipes 2",
            ManagedObjectModelVersionHashesKey => new Dictionary([
                "Recipes" => $first->versionChecksum,
                "Recipes 2" => $second->versionChecksum,
            ]),
        ]));

        $url = new ManagedObjectModelBundle($this->url($bundle))->versionURL($first->versionChecksum);

        $this->assertNotNull($url, "a checksum in the version information must resolve to its version");
        $this->assertNotNull(new ManagedObjectModel($url)->entitiesByName["Recipe"]?->attributesByName["directions"], "the resolved version must be the one the checksum names, not the current one");
    }

    /**
     * A reference describes one version of a model, and the checksum is what picks it. Before the
     * bundle existed there was nothing to pick from, so the checksum was carried and ignored.
     */
    public function testAReferenceResolvesTheVersionItsChecksumNames(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $first = self::model("directions");
        $second = self::model("instructions");
        $this->writeModel($bundle . "/Recipes.mom", $first);
        $this->writeModel($bundle . "/Recipes 2.mom", $second);
        $this->writeVersionInfo($bundle, new Dictionary([
            ManagedObjectModelCurrentVersionNameKey => "Recipes 2",
            ManagedObjectModelVersionHashesKey => new Dictionary([
                "Recipes" => $first->versionChecksum,
                "Recipes 2" => $second->versionChecksum,
            ]),
        ]));

        $reference = ManagedObjectModelReference::fileURL($this->url($bundle), $first->versionChecksum);

        $this->assertNotNull($reference->resolvedModel->entitiesByName["Recipe"]?->attributesByName["directions"], "the reference must resolve the version its checksum names, not the current one");
        $this->assertNull($reference->resolvedModel->entitiesByName["Recipe"]?->attributesByName["instructions"]);
    }

    /** A model file on its own is the only version there is, so the checksum is not a selector. */
    public function testAReferenceToAModelFileResolvesThatFile(): void
    {
        $model = self::model("directions");
        $this->writeModel("Recipes." . ManagedObjectModelFileExtension, $model);

        $reference = ManagedObjectModelReference::fileURL($this->url("Recipes." . ManagedObjectModelFileExtension), $model->versionChecksum);

        $this->assertNotNull($reference->resolvedModel->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    public function testABundleNamingAMissingVersionResolvesToNothing(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeVersionInfo($bundle, new Dictionary([ManagedObjectModelCurrentVersionNameKey => "Absent"]));

        $this->assertNull(new ManagedObjectModelBundle($this->url($bundle))->currentVersionURL);
    }
}
