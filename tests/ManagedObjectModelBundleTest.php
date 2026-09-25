<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\ManagedObjectModelBundle;
use Sabatier\CoreData\ManagedObjectModelReference;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\PropertyListSerialization;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
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
    private URL $resources;

    /** @throws Exception */
    #[Override]
    protected function setUp(): void
    {
        $this->resources = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathComponent("Resources");
        FileManager::default()->createDirectory($this->resources, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            FileManager::default()->removeItem($this->resources->deletingLastPathComponent());
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
        return $this->resources->appendingPathComponent($relativePath);
    }

    private function containingBundle(): Bundle
    {
        return Bundle::bundleWithURL($this->resources->deletingLastPathComponent());
    }

    /** @throws Exception */
    private function writeModel(string $relativePath, ManagedObjectModel $model): void
    {
        FileManager::default()->createFile($this->url($relativePath)->path, KeyedArchiver::archivedData($model));
    }

    /**
     * @throws Exception
     * @noinspection PhpSameParameterValueInspection
     */
    private function makeBundle(string $name): string
    {
        $bundle = $name . "." . ManagedObjectModelBundleFileExtension;
        FileManager::default()->createDirectory($this->url($bundle), true);
        return $bundle;
    }

    /**
     * @param Dictionary<mixed> $versionInfo
     * @throws Exception
     */
    private function writeVersionInfo(string $bundle, Dictionary $versionInfo): void
    {
        FileManager::default()->createFile($this->url($bundle)->appendingPathComponent("VersionInfo.plist")->path, PropertyListSerialization::data($versionInfo));
    }

    /** @throws Exception */
    public function testAModelFileOnItsOwnStillLoads(): void
    {
        $this->writeModel("Recipes." . ManagedObjectModelFileExtension, self::model("directions"));

        $model = new ManagedObjectModel($this->url("Recipes." . ManagedObjectModelFileExtension));

        $this->assertNotNull($model->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    /** @throws Exception */
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

    /**
     * A bundle assembled by hand may carry no version information, the way an unvalidated bundle does elsewhere.
     *
     * @throws Exception
     */
    public function testABundleWithoutVersionInformationFallsBackToItsOwnName(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("directions"));

        $model = new ManagedObjectModel($this->url($bundle));

        $this->assertNotNull($model->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    /** @throws Exception */
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

        $url = new ManagedObjectModelBundle($this->url($bundle))->urlForModelVersionWithChecksum($first->versionChecksum);

        $this->assertNotNull($url, "a checksum in the version information must resolve to its version");
        $this->assertNotNull(new ManagedObjectModel($url)->entitiesByName["Recipe"]?->attributesByName["directions"], "the resolved version must be the one the checksum names, not the current one");
    }

    /**
     * A reference describes one version of a model, and the checksum is what picks it. Before the
     * bundle existed there was nothing to pick from, so the checksum was carried and ignored.
     *
     * @throws Exception
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

    /**
     * A model file on its own is the only version there is, so the checksum is not a selector.
     *
     * @throws Exception
     */
    public function testAReferenceToAModelFileResolvesThatFile(): void
    {
        $model = self::model("directions");
        $this->writeModel("Recipes." . ManagedObjectModelFileExtension, $model);

        $reference = ManagedObjectModelReference::fileURL($this->url("Recipes." . ManagedObjectModelFileExtension), $model->versionChecksum);

        $this->assertNotNull($reference->resolvedModel->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    /** @throws Exception */
    public function testThePackageListsTheVersionsItHolds(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("directions"));
        $this->writeModel($bundle . "/Recipes 2.mom", self::model("instructions"));

        $versions = new ManagedObjectModelBundle($this->url($bundle))->modelVersions;

        $this->assertEqualsCanonicalizing(["Recipes", "Recipes 2"], $versions->array);
    }

    /** @throws Exception */
    public function testThePackageNamesItsCurrentVersion(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("directions"));
        $this->writeModel($bundle . "/Recipes 2.mom", self::model("instructions"));
        $this->writeVersionInfo($bundle, new Dictionary([ManagedObjectModelCurrentVersionNameKey => "Recipes 2"]));

        $this->assertSame("Recipes 2", new ManagedObjectModelBundle($this->url($bundle))->currentVersion);
    }

    /**
     * A version the package does not hold is not its current version, however the version information names it.
     *
     * @throws Exception
     */
    public function testAVersionInformationNamingAMissingVersionHasNoCurrentVersion(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeVersionInfo($bundle, new Dictionary([ManagedObjectModelCurrentVersionNameKey => "Absent"]));

        $this->assertNull(new ManagedObjectModelBundle($this->url($bundle))->currentVersion);
    }

    /** @throws Exception */
    public function testTheChecksumsComeFromTheVersionInformation(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $model = self::model("directions");
        $this->writeModel($bundle . "/Recipes.mom", $model);
        $this->writeVersionInfo($bundle, new Dictionary([
            ManagedObjectModelVersionHashesKey => new Dictionary(["Recipes" => $model->versionChecksum]),
        ]));

        $this->assertSame($model->versionChecksum, new ManagedObjectModelBundle($this->url($bundle))->versionChecksums["Recipes"]);
    }

    /**
     * A model file is not a package, so a bundle over one holds no versions. Asking is what lets a
     * caller stay ignorant of which layout it was handed.
     */
    /**
     * A consumer that knows only the model's name gets the package, because that is the layout carrying
     * the version the store asks for. Locating by the model file's extension alone would walk straight past it.
     *
     * @throws Exception
     */
    public function testTheNamedModelResolvesToThePackage(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("directions"));

        $url = ManagedObjectModelBundle::urlForModelNamed("Recipes", $this->containingBundle());

        $this->assertSame($this->url($bundle)->path, $url?->path);
    }

    /**
     * Every project today ships a lone model file, and it has to keep resolving.
     *
     * @throws Exception
     */
    public function testTheNamedModelFallsBackToALoneModelFile(): void
    {
        $this->writeModel("Recipes." . ManagedObjectModelFileExtension, self::model("directions"));

        $url = ManagedObjectModelBundle::urlForModelNamed("Recipes", $this->containingBundle());

        $this->assertSame($this->url("Recipes." . ManagedObjectModelFileExtension)->path, $url?->path);
    }

    /**
     * A lone model file beside a package of the same name wins. A project that ships the file must keep
     * loading exactly what it loaded before packages existed; a project that moved to a package no longer
     * ships the file, so the two only ever coexist by accident.
     *
     * @throws Exception
     */
    public function testALoneModelFileWinsOverAPackageOfTheSameName(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("instructions"));
        $this->writeModel("Recipes." . ManagedObjectModelFileExtension, self::model("directions"));

        $url = ManagedObjectModelBundle::urlForModelNamed("Recipes", $this->containingBundle());

        $this->assertSame($this->url("Recipes." . ManagedObjectModelFileExtension)->path, $url?->path);
    }

    public function testAnAbsentNamedModelResolvesToNothing(): void
    {
        $this->assertNull(ManagedObjectModelBundle::urlForModelNamed("Recipes", $this->containingBundle()));
    }

    /**
     * The whole point of locating by name: a container asked for a name ends up with the package's current
     * version, without any caller having to know which of the two layouts is on disk.
     *
     * @throws Exception
     */
    public function testTheNamedModelLoadsThePackagesCurrentVersion(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeModel($bundle . "/Recipes.mom", self::model("directions"));
        $this->writeModel($bundle . "/Recipes 2.mom", self::model("instructions"));
        $this->writeVersionInfo($bundle, new Dictionary([ManagedObjectModelCurrentVersionNameKey => "Recipes 2"]));

        $model = new ManagedObjectModel(ManagedObjectModelBundle::urlForModelNamed("Recipes", $this->containingBundle()));

        $this->assertNotNull($model->entitiesByName["Recipe"]?->attributesByName["instructions"], "the named model must load the version the package names as current");
        $this->assertNull($model->entitiesByName["Recipe"]?->attributesByName["directions"]);
    }

    /** @throws Exception */
    public function testAModelFileIsNotAPackage(): void
    {
        $this->writeModel("Recipes." . ManagedObjectModelFileExtension, self::model("directions"));

        $bundle = new ManagedObjectModelBundle($this->url("Recipes." . ManagedObjectModelFileExtension));

        $this->assertNull($bundle->currentVersion);
        $this->assertNull($bundle->currentVersionURL);
        $this->assertSame(0, $bundle->modelVersions->count);
    }

    /** @throws Exception */
    public function testABundleNamingAMissingVersionResolvesToNothing(): void
    {
        $bundle = $this->makeBundle("Recipes");
        $this->writeVersionInfo($bundle, new Dictionary([ManagedObjectModelCurrentVersionNameKey => "Absent"]));

        $this->assertNull(new ManagedObjectModelBundle($this->url($bundle))->currentVersionURL);
    }
}
