<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PDO;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\PropertyListSerialization;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use const Sabatier\CoreData\ManagedObjectModelFileExtension;
use const Sabatier\CoreData\ManagedObjectModelURLOption;
use const Sabatier\Foundation\kCFBundleIdentifierKey;
use const Sabatier\Foundation\kCFBundleNameKey;
use const Sabatier\Foundation\kCFBundlePackageTypeKey;

/**
 * @property string $serial
 */
final class ReplaceWidget extends ManagedObject
{
}

/**
 * Covers replacing one SQL store with another: moving a whole database's tables into a second
 * database and destroying the first.
 *
 * This is what renaming a project does. The database is named after the model, so a rename has
 * to carry the rows from "sql://Old" to "sql://New" — replacePersistentStore is the operation
 * that does it, and with it the two private steps only it reaches, dropDatabase and moveTables.
 * Neither was exercised.
 *
 * The fixture is a minimal generated bundle, because the operation builds its own stack rather
 * than taking a coordinator's and so reads each model from disk. That means the layout matters:
 * a model lives at <Bundle>/Resources/<Name>.mom beside an Info.plist, and BOTH models sit in the
 * SAME bundle, exactly as a rename leaves them — it copies Old.mom to New.mom before replacing
 * the store, so the two versions coexist. One bundle, built once for the class.
 *
 * Keeping it to one bundle is not tidiness. Loading a model registers its directory in Bundle's
 * static registry, which is never emptied, and every later migration enumerates every registered
 * bundle looking for mapping models. Measured: a throwaway directory per test inside the system
 * temporary directory took the 35 migration tests from 15.7s to 11m14s and broke six of them.
 */
final class SQLReplaceStoreTest extends SQLMigrationTestCase
{
    /** The database the rename starts from, named after the source model as a project's is. */
    private const string SourceModelName = "CoreDataReplaceOld";
    /** The database the rename moves to. The destination is the inherited test database, so this is only the model's name. */
    private const string DestinationModelName = "CoreDataReplaceNew";

    /** The bundle holding both models, built once for the whole class. */
    private static ?URL $bundleURL = null;

    /** @var list<ManagedObjectContext> The stacks this test opened over the source database, released in tearDown. */
    private array $sourceContexts = [];

    private static function model(): ManagedObjectModel
    {
        $serial = new AttributeDescription();
        $serial->name = "serial";
        $serial->type = AttributeType::string;

        $widget = new EntityDescription();
        $widget->name = "ReplaceWidget";
        $widget->managedObjectClassName = ReplaceWidget::class;
        $widget->properties = new ArrayClass([$serial]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget]);
        return $model;
    }

    /**
     * A generated bundle carrying one model file per name under Resources, with the Info.plist
     * that makes the directory a bundle at all.
     *
     * @throws Exception
     */
    private static function createBundle(): URL
    {
        $fileManager = FileManager::default();
        $bundleURL = $fileManager->temporaryDirectory->appendingPathComponent(new UUID()->uuidString);
        $resourcesURL = $bundleURL->appendingPathComponent("Resources");
        $fileManager->createDirectory($resourcesURL, true);

        /** @var Dictionary<mixed> $info */
        $info = new Dictionary([
            kCFBundleNameKey => self::SourceModelName,
            kCFBundleIdentifierKey => "com.sabatier.coredata.tests.replace",
            kCFBundlePackageTypeKey => "APPL",
        ]);
        PropertyListSerialization::writePropertyList($info, $bundleURL->appendingPathComponent("Info")->appendingPathExtension("plist"));

        $data = KeyedArchiver::archivedData(self::model());
        foreach ([self::SourceModelName, self::DestinationModelName] as $name) {
            $fileManager->createFile(self::modelURLIn($resourcesURL, $name)->path, $data);
        }
        return $bundleURL;
    }

    private static function modelURLIn(URL $resourcesURL, string $name): URL
    {
        return $resourcesURL->appendingPathComponent($name)->appendingPathExtension(ManagedObjectModelFileExtension);
    }

    /**
     * The options naming the model a store is opened with, the way a rename passes them.
     *
     * @return Dictionary<mixed>
     */
    private function optionsFor(string $name): Dictionary
    {
        return new Dictionary([ManagedObjectModelURLOption => self::modelURLIn(self::$bundleURL->appendingPathComponent("Resources"), $name)]);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::$bundleURL ??= self::createBundle();
        $this->pdo->exec("DROP DATABASE IF EXISTS `" . self::SourceModelName . "`");
    }

    #[Override]
    protected function tearDown(): void
    {
        // The same leak the base class guards against: a coordinator registers its context as a
        // notification observer, which keeps the whole stack — and its connection — alive.
        foreach ($this->sourceContexts as $context) {
            $context->persistentStoreCoordinator = null;
        }
        $this->sourceContexts = [];
        $this->pdo->exec("DROP DATABASE IF EXISTS `" . self::SourceModelName . "`");
        parent::tearDown();
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        if (self::$bundleURL !== null) {
            FileManager::default()->removeItem(self::$bundleURL);
            self::$bundleURL = null;
        }
    }

    /**
     * A stack over $url, which the base class cannot open because it always targets its own
     * database. Kept so the source database can be seeded before it is moved.
     *
     * @throws Exception
     */
    private function openStore(URL $url): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $url);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->sourceContexts[] = $context;
        return $context;
    }

    private function databaseExists(string $databaseName): bool
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $statement->execute([$databaseName]);
        return (bool)$statement->fetchColumn();
    }

    /**
     * The serials stored in $databaseName, read straight from the table.
     *
     * @return list<string>
     */
    private function serialsIn(string $databaseName): array
    {
        $sql = sprintf("SELECT `serial` FROM `%s`.`ReplaceWidget`", str_replace("`", "``", $databaseName));
        return array_map(strval(...), $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Replacing carries the source's rows into the destination and destroys the source, which is
     * what makes a renamed project keep its data.
     *
     * @throws Exception
     */
    public function testReplacingMovesTheTablesAndDestroysTheSource(): void
    {
        $sourceURL = new URL("sql://" . self::SourceModelName);
        $context = $this->openStore($sourceURL);
        $widget = new ReplaceWidget($context);
        $widget->serial = "S-1";
        $context->save();

        $this->assertSame(["S-1"], $this->serialsIn(self::SourceModelName), "precondition: the row lives in the source database");
        $this->bootstrap(self::model());
        $this->assertSame([], $this->serialsIn(static::DATABASE_NAME), "precondition: the destination starts empty");

        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->replacePersistentStore(
            $this->storeURL,
            $this->optionsFor(self::DestinationModelName),
            $sourceURL,
            $this->optionsFor(self::SourceModelName),
            PersistentStoreType::sql,
        );

        $this->assertSame(["S-1"], $this->serialsIn(static::DATABASE_NAME), "the source's rows moved into the destination");
        $this->assertFalse($this->databaseExists(self::SourceModelName), "the source database is destroyed");
    }
}
