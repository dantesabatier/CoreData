<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\XMLObjectStore;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use const Sabatier\CoreData\StoreTypeKey;
use const Sabatier\CoreData\StoreUUIDKey;

/**
 * @property string $serial
 */
final class XMLMetadataWidget extends ManagedObject
{
}

/**
 * Covers reading an XML store's metadata without opening it.
 *
 * The accessor is static and takes a URL, so a caller can ask what a file holds before deciding
 * to open it — the same contract the SQL store answers. It never read the file: it parsed a
 * DOMDocument it had just constructed, so a valid store on disk answered with nothing at all.
 *
 * Nothing reached it, which is why it went unnoticed. Its one internal caller resolves the store
 * class by asking each registered type what it is, and that lookup is short-circuited whenever
 * the requested type is registered — which all four are.
 *
 * A URL with no store behind it is the caller's error, and now fails as one, naming the path
 * rather than reaching an assertion two levels down that production has switched off anyway.
 */
final class XMLStoreMetadataTest extends TestCase
{
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $serial = new AttributeDescription();
        $serial->name = "serial";
        $serial->type = AttributeType::string;

        $widget = new EntityDescription();
        $widget->name = "XMLMetadataWidget";
        $widget->managedObjectClassName = XMLMetadataWidget::class;
        $widget->properties = new ArrayClass([$serial]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget]);
        return $model;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");
    }

    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    /**
     * Writes one object through a full stack, leaving a store file on disk.
     *
     * @throws Exception
     */
    private function seed(): void
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $widget = new XMLMetadataWidget($context);
        $widget->serial = "S-1";
        $context->save();
        $context->persistentStoreCoordinator = null;
    }

    /**
     * A store on disk answers with the metadata it actually holds.
     *
     * @throws Exception
     */
    public function testAStoreOnDiskReportsItsOwnMetadata(): void
    {
        $this->seed();

        $metadata = XMLObjectStore::metadataForPersistentStore($this->storeURL);

        $this->assertSame("xml", (string)$metadata[StoreTypeKey], "the metadata names the store type");
        $this->assertNotNull($metadata[StoreUUIDKey], "and carries the identifier the store wrote");
    }

    /**
     * A URL with no store behind it fails naming the path, rather than parsing an empty document
     * and answering as though the store were there.
     *
     * @throws Exception
     */
    public function testAMissingStoreFailsNamingThePath(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/No persistent store found/");

        XMLObjectStore::metadataForPersistentStore($this->storeURL);
    }

    /**
     * And the coordinator's own accessor routes to it, which is the path a caller actually takes.
     *
     * @throws Exception
     */
    public function testTheCoordinatorRoutesToTheStoresAccessor(): void
    {
        $this->seed();

        $metadata = PersistentStoreCoordinator::metadataForPersistentStore(PersistentStoreType::xml, $this->storeURL);

        $this->assertSame("xml", (string)$metadata[StoreTypeKey], "the coordinator answers with the store's own metadata");
    }
}
