<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\EntityMapping;
use Sabatier\CoreData\EntityMappingType;
use Sabatier\CoreData\EntityMigrationPolicy;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MappingModel;
use Sabatier\CoreData\MigrationManager;
use Sabatier\Foundation\ArrayClass;

final class GateMigrationPolicy extends EntityMigrationPolicy
{
}

/**
 * The checks a migration performs on its mapping model before opening either store.
 *
 * They exist for mapping models the framework did not build: an inferred one is correct by
 * construction, but one read from a file can name a policy that cannot migrate anything, or record
 * the version hashes of a model version that is no longer the one being migrated.
 */
final class MigrationValidationGateTest extends TestCase
{
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
        $entity = new EntityDescription();
        $entity->name = "Gate";
        $entity->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private static function sourceModel(): ManagedObjectModel
    {
        return self::model([self::attribute("name", AttributeType::string)]);
    }

    private static function destinationModel(): ManagedObjectModel
    {
        return self::model([self::attribute("name", AttributeType::string), self::attribute("size", AttributeType::integer32)]);
    }

    private static function mappingModel(EntityMapping $entityMapping): MappingModel
    {
        $mappingModel = new MappingModel();
        $mappingModel->entityMappings = new ArrayClass([$entityMapping]);
        return $mappingModel;
    }

    private static function entityMapping(EntityMappingType $mappingType = EntityMappingType::transformEntityMappingType): EntityMapping
    {
        $entityMapping = new EntityMapping();
        $entityMapping->sourceEntityName = "Gate";
        $entityMapping->destinationEntityName = "Gate";
        $entityMapping->mappingType = $mappingType;
        return $entityMapping;
    }

    /** @throws Exception */
    public function testAnInferredMappingModelPassesBothChecks(): void
    {
        $sourceModel = self::sourceModel();
        $destinationModel = self::destinationModel();
        $mappingModel = MappingModel::inferredMappingModel($sourceModel, $destinationModel);

        $this->assertTrue(MigrationManager::canMigrateWithMappingModel($mappingModel));
        $this->assertTrue(MigrationManager::performSanityCheck($mappingModel, $sourceModel, $destinationModel));
    }

    /** @throws Exception */
    public function testAMappingWithoutATypeCannotMigrate(): void
    {
        $mappingModel = self::mappingModel(self::entityMapping(EntityMappingType::undefinedEntityMappingType));

        $this->assertFalse(MigrationManager::canMigrateWithMappingModel($mappingModel));
    }

    /** @throws Exception */
    public function testACustomMappingWithoutAPolicyCannotMigrate(): void
    {
        $mappingModel = self::mappingModel(self::entityMapping(EntityMappingType::customEntityMappingType));

        $this->assertFalse(MigrationManager::canMigrateWithMappingModel($mappingModel));
    }

    /** @throws Exception */
    public function testACustomMappingWithAPolicyCanMigrate(): void
    {
        $entityMapping = self::entityMapping(EntityMappingType::customEntityMappingType);
        $entityMapping->entityMigrationPolicyClassName = GateMigrationPolicy::class;

        $this->assertTrue(MigrationManager::canMigrateWithMappingModel(self::mappingModel($entityMapping)));
    }

    /** @throws Exception */
    public function testAVersionHashThatDisagreesWithTheModelFailsTheSanityCheck(): void
    {
        $sourceModel = self::sourceModel();
        $destinationModel = self::destinationModel();

        $entityMapping = self::entityMapping();
        $entityMapping->sourceEntityVersionHash = "a hash from another version of the entity";

        $this->assertFalse(MigrationManager::performSanityCheck(self::mappingModel($entityMapping), $sourceModel, $destinationModel));
    }

    /**
     * A hand-authored mapping model may leave the hashes unset, and may cover only some of the
     * entities in the models, so neither is treated as a disagreement.
     *
     * @throws Exception
     */
    public function testAnUnsetHashAndAnUnmentionedEntityPassTheSanityCheck(): void
    {
        $sourceModel = self::sourceModel();
        $destinationModel = self::destinationModel();

        $this->assertTrue(MigrationManager::performSanityCheck(self::mappingModel(self::entityMapping()), $sourceModel, $destinationModel));

        $entityMapping = self::entityMapping();
        $entityMapping->sourceEntityName = "Absent";
        $entityMapping->destinationEntityName = "Absent";
        $entityMapping->sourceEntityVersionHash = "a hash for an entity neither model declares";

        $this->assertTrue(MigrationManager::performSanityCheck(self::mappingModel($entityMapping), $sourceModel, $destinationModel));
    }
}
