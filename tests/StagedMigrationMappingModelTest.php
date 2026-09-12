<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\CustomMigrationStage;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\EntityMapping;
use Sabatier\CoreData\EntityMappingType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\ManagedObjectModelReference;
use Sabatier\CoreData\MappingModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\PropertyMapping;
use Sabatier\CoreData\StagedMigrationManager;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\MappingModelFileExtension;
use const Sabatier\CoreData\MigratePersistentStoresAutomaticallyOption;
use const Sabatier\CoreData\PersistentStoreStagedMigrationManagerOptionKey;

/**
 * @property string $body
 */
final class StagedNote extends ManagedObject
{
}

/**
 * A staged migration uses the mapping model authored for the pair it is migrating.
 *
 * A custom stage exists for a change the framework cannot infer, so inferring one regardless left
 * the stage unable to do the one thing it is for: the inference raises rather than producing a
 * mapping. Searching for a mapping model first is what makes the stage usable — a mapping model
 * authored for that exact pair of versions is itself the declaration that it must be used.
 */
final class StagedMigrationMappingModelTest extends SQLMigrationTestCase
{
    private URL $bundleURL;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->bundleURL = FileManager::default()->temporaryDirectory->appendingPathComponent(new UUID()->uuidString);
        FileManager::default()->createDirectory($this->bundleURL, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->bundleURL);
        parent::tearDown();
    }

    private function bundle(): Bundle
    {
        return Bundle::bundleWithURL($this->bundleURL);
    }

    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        return $attribute;
    }

    /**
     * @param list<AttributeDescription> $attributes
     */
    private static function model(array $attributes): ManagedObjectModel
    {
        $note = new EntityDescription();
        $note->name = "StagedNote";
        $note->managedObjectClassName = StagedNote::class;
        $note->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$note]);
        return $model;
    }

    private static function sourceModel(): ManagedObjectModel
    {
        return self::model([self::attribute("body", AttributeType::string)]);
    }

    /**
     * The destination turns the attribute into binary data, a change canTransformAttributeType
     * refuses — so inference raises and only an authored mapping model can migrate this pair.
     */
    private static function destinationModel(): ManagedObjectModel
    {
        return self::model([self::attribute("body", AttributeType::binaryData)]);
    }

    private function writeMappingModel(ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel): void
    {
        $entityMapping = new EntityMapping();
        $entityMapping->sourceEntityName = "StagedNote";
        $entityMapping->destinationEntityName = "StagedNote";
        $entityMapping->sourceEntityVersionHash = $sourceModel->entitiesByName["StagedNote"]?->versionHash;
        $entityMapping->destinationEntityVersionHash = $destinationModel->entitiesByName["StagedNote"]?->versionHash;
        $entityMapping->mappingType = EntityMappingType::transformEntityMappingType;
        $entityMapping->attributeMappings = new ArrayClass([
            new PropertyMapping("body", Expression::expressionWithFormat("\$source.body")),
        ]);

        $mappingModel = new MappingModel();
        $mappingModel->sourceModel = $sourceModel;
        $mappingModel->destinationModel = $destinationModel;
        $mappingModel->entityMappings = new ArrayClass([$entityMapping]);

        FileManager::default()->createFile($this->bundleURL->appendingPathComponent("StagedNote." . MappingModelFileExtension)->path, KeyedArchiver::archivedData($mappingModel));
    }

    /**
     * @param Dictionary<mixed> $options
     */
    private function openWithStages(ManagedObjectModel $destinationModel, Dictionary $options): void
    {
        $coordinator = new PersistentStoreCoordinator($destinationModel);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $this->storeURL, $options);
    }

    private function stagedOptions(ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel): Dictionary
    {
        $stage = new CustomMigrationStage(
            new ManagedObjectModelReference($sourceModel, $sourceModel->versionChecksum),
            new ManagedObjectModelReference($destinationModel, $destinationModel->versionChecksum),
        );
        return new Dictionary([
            MigratePersistentStoresAutomaticallyOption => true,
            PersistentStoreStagedMigrationManagerOptionKey => new StagedMigrationManager(new ArrayClass([$stage])),
        ]);
    }

    public function testACustomStageUsesTheMappingModelAuthoredForThePair(): void
    {
        $sourceModel = self::sourceModel();
        $context = $this->bootstrap($sourceModel);
        $note = new StagedNote($context);
        $note->body = "kept";
        $context->save();

        $destinationModel = self::destinationModel();
        $this->writeMappingModel($sourceModel, $destinationModel);
        // Materialise the bundle so allBundles() can see it, the way a deployed project's Resources directory is seen.
        $this->bundle();

        $this->openWithStages($destinationModel, $this->stagedOptions($sourceModel, $destinationModel));

        $this->assertSame("longblob", $this->columnType("StagedNote", "body"), "the authored mapping model must have driven the migration");
    }
}
