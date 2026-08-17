<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\EntityMapping;
use Sabatier\CoreData\EntityMappingType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MappingModel;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\PropertyMapping;
use Sabatier\CoreData\SQLInPlaceMigrationManager;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\URL;

final class MappedLedger extends ManagedObject
{
}

/**
 * Migrating with a mapping model read from a file.
 *
 * The migration engine takes a mapping model and runs it; a lightweight migration only differs in
 * that it infers that model first. This exercises the other half — a mapping model authored by hand,
 * written to a file, loaded back, and handed to the manager — which is the case the framework was
 * built for but has never actually run.
 */
final class MappingModelMigrationTest extends SQLMigrationTestCase
{
    private string $mappingPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mappingPath = sys_get_temp_dir() . "/" . uniqid("migration", true) . ".map";
    }

    protected function tearDown(): void
    {
        if (is_file($this->mappingPath)) {
            unlink($this->mappingPath);
        }
        parent::tearDown();
    }

    private static function attribute(string $name, AttributeType $type, ?string $renamingIdentifier = null): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        if ($renamingIdentifier !== null) {
            $attribute->renamingIdentifier = $renamingIdentifier;
        }
        return $attribute;
    }

    /**
     * @param list<AttributeDescription> $attributes
     */
    private static function model(array $attributes): ManagedObjectModel
    {
        $ledger = new EntityDescription();
        $ledger->name = "Ledger";
        $ledger->managedObjectClassName = MappedLedger::class;
        $ledger->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$ledger]);
        return $model;
    }

    private static function sourceModel(): ManagedObjectModel
    {
        return self::model([self::attribute("reference", AttributeType::string), self::attribute("note", AttributeType::string)]);
    }

    private static function destinationModel(): ManagedObjectModel
    {
        return self::model([self::attribute("reference", AttributeType::string), self::attribute("memo", AttributeType::string, renamingIdentifier: "note")]);
    }

    /**
     * Writes the mapping model to disk and reads it back, so the migration runs against a mapping
     * model that genuinely came from a file rather than the one built here.
     */
    private function mappingModelFromFile(ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel): MappingModel
    {
        $mapping = new EntityMapping();
        $mapping->sourceEntityName = "Ledger";
        $mapping->destinationEntityName = "Ledger";
        $mapping->sourceEntityVersionHash = $sourceModel->entitiesByName["Ledger"]?->versionHash;
        $mapping->destinationEntityVersionHash = $destinationModel->entitiesByName["Ledger"]?->versionHash;
        $mapping->mappingType = EntityMappingType::transformEntityMappingType;
        $mapping->attributeMappings = new ArrayClass([
            new PropertyMapping("reference", Expression::expressionWithFormat("\$source.reference")),
            new PropertyMapping("memo", Expression::expressionWithFormat("\$source.note")),
        ]);

        $mappingModel = new MappingModel();
        $mappingModel->sourceModel = $sourceModel;
        $mappingModel->destinationModel = $destinationModel;
        $mappingModel->entityMappings = new ArrayClass([$mapping]);

        file_put_contents($this->mappingPath, KeyedArchiver::archivedData($mappingModel));
        return new MappingModel(new URL("file:///" . str_replace("\\", "/", $this->mappingPath)));
    }

    public function testMigrationDrivenByAMappingModelFromAFile(): void
    {
        $sourceModel = self::sourceModel();
        $context = $this->bootstrap($sourceModel);
        $ledger = new MappedLedger($context);
        $ledger->reference = "LDG-1";
        $ledger->note = "carried over";
        $context->save();

        $destinationModel = self::destinationModel();
        $mappingModel = $this->mappingModelFromFile($sourceModel, $destinationModel);

        $manager = new SQLInPlaceMigrationManager($mappingModel->sourceModel, $mappingModel->destinationModel);
        $ok = $manager->migrateStore($this->storeURL, PersistentStoreType::sql, null, $mappingModel, $this->storeURL, PersistentStoreType::sql, null);

        $this->assertTrue($ok, "the migration must report success");
        $this->assertTrue($this->hasColumn("Ledger", "memo"), "the destination attribute must exist after the migration");
        $this->assertSame(["LDG-1"], $this->columnValues("Ledger", "reference"), "an unchanged attribute must survive");
        $this->assertSame(["carried over"], $this->columnValues("Ledger", "memo"), "the value must arrive under the renamed attribute");
        $this->assertFalse($this->hasColumn("Ledger", "note"), "the source attribute must be gone after the migration");
    }
}
