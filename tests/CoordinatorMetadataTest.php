<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use const Sabatier\CoreData\StoreTypeKey;
use const Sabatier\CoreData\StoreUUIDKey;

/**
 * @property string $serial
 */
final class MetadataWidget extends ManagedObject
{
}

/**
 * Covers reading and writing a store's metadata without opening it.
 *
 * Both accessors are static and take a URL rather than a store, which is the point: they answer
 * for a store on disk that no coordinator has added, so a caller can ask what a database holds
 * before deciding to open it. Neither was exercised.
 *
 * The store type is what the coordinator routes on, so the pair also pins that a database with
 * no metadata table still answers — with the type it is, rather than failing.
 */
final class CoordinatorMetadataTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $serial = new AttributeDescription();
        $serial->name = "serial";
        $serial->type = AttributeType::string;

        $widget = new EntityDescription();
        $widget->name = "MetadataWidget";
        $widget->managedObjectClassName = MetadataWidget::class;
        $widget->properties = new ArrayClass([$serial]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget]);
        return $model;
    }

    /**
     * A bootstrapped store reports the type it is, so a caller can route on it without opening
     * the store.
     *
     * @throws Exception
     */
    public function testAStoreReportsItsTypeThroughTheStaticAccessor(): void
    {
        $this->bootstrap(self::model());

        $metadata = PersistentStoreCoordinator::metadataForPersistentStore(PersistentStoreType::sql, $this->storeURL);

        $this->assertNotNull($metadata[StoreTypeKey], "the metadata names the store type");
    }

    /**
     * Metadata written through the static accessor is read back by it.
     *
     * @throws Exception
     */
    public function testMetadataWrittenStaticallyIsReadBack(): void
    {
        $this->bootstrap(self::model());

        PersistentStoreCoordinator::setMetadataForPersistentStore(
            new Dictionary([StoreTypeKey => "SQL", StoreUUIDKey => "fixed-uuid-for-the-test", "CustomKey" => "custom value"]),
            PersistentStoreType::sql,
            $this->storeURL,
        );
        $metadata = PersistentStoreCoordinator::metadataForPersistentStore(PersistentStoreType::sql, $this->storeURL);

        $this->assertSame("custom value", (string)$metadata["CustomKey"], "a key written through the accessor is read back");
        $this->assertSame("fixed-uuid-for-the-test", (string)$metadata[StoreUUIDKey], "and so is the store identifier");
    }

    /**
     * The options passed to the setter are merged into what is stored, which is how a store's
     * configuration travels with its metadata.
     *
     * @throws Exception
     */
    public function testTheOptionsAreMergedIntoTheStoredMetadata(): void
    {
        $this->bootstrap(self::model());

        PersistentStoreCoordinator::setMetadataForPersistentStore(
            new Dictionary([StoreTypeKey => "SQL"]),
            PersistentStoreType::sql,
            $this->storeURL,
            new Dictionary(["OptionKey" => "option value"]),
        );
        $metadata = PersistentStoreCoordinator::metadataForPersistentStore(PersistentStoreType::sql, $this->storeURL);

        $this->assertSame("option value", (string)$metadata["OptionKey"], "the option reached the stored metadata");
    }
}
