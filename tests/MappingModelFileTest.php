<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\EntityMapping;
use Sabatier\CoreData\EntityMappingType;
use Sabatier\CoreData\EntityMigrationPolicy;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MappingModel;
use Sabatier\CoreData\PropertyMapping;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\URL;

/** A policy the mapping model names, to pin that the class name survives being written to a file. */
final class RecipeMigrationPolicy extends EntityMigrationPolicy
{
}

final class MappingRecipe extends ManagedObject
{
}

/**
 * Loading a mapping model from a file.
 *
 * A mapping model carries the two managed object models it was authored against, so reading one
 * back yields both the entity mappings and the model pair they were written for — the same
 * archive-and-restore path ManagedObjectModel uses for a model file.
 */
final class MappingModelFileTest extends TestCase
{
    private string $path;

    #[Override]
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . "/" . uniqid("mapping", true) . ".map";
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function url(): URL
    {
        return new URL("file:///" . str_replace("\\", "/", $this->path));
    }

    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        return $attribute;
    }

    private static function model(string $attributeName): ManagedObjectModel
    {
        $recipe = new EntityDescription();
        $recipe->name = "Recipe";
        $recipe->managedObjectClassName = MappingRecipe::class;
        $recipe->properties = new ArrayClass([self::attribute($attributeName, AttributeType::string)]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$recipe]);
        return $model;
    }

    private function writeMappingModel(): MappingModel
    {
        $sourceModel = self::model("directions");
        $destinationModel = self::model("instructions");

        $mapping = new EntityMapping();
        $mapping->sourceEntityName = "Recipe";
        $mapping->destinationEntityName = "Recipe";
        $mapping->sourceEntityVersionHash = $sourceModel->entitiesByName["Recipe"]?->versionHash;
        $mapping->destinationEntityVersionHash = $destinationModel->entitiesByName["Recipe"]?->versionHash;
        $mapping->mappingType = EntityMappingType::transformEntityMappingType;
        $mapping->entityMigrationPolicyClassName = RecipeMigrationPolicy::class;
        $mapping->attributeMappings = new ArrayClass([
            new PropertyMapping("instructions", Expression::expressionWithFormat("\$source.directions")),
        ]);

        $mappingModel = new MappingModel();
        $mappingModel->sourceModel = $sourceModel;
        $mappingModel->destinationModel = $destinationModel;
        $mappingModel->entityMappings = new ArrayClass([$mapping]);

        file_put_contents($this->path, KeyedArchiver::archivedData($mappingModel));
        return $mappingModel;
    }

    public function testEntityMappingsSurviveARoundTrip(): void
    {
        $written = $this->writeMappingModel();

        $read = new MappingModel($this->url());

        $this->assertSame(["RecipeToRecipe"], $read->entityMappingsByName->keys->array, "the entity mapping must be restored under its name");
        $mapping = $read->entityMappingsByName["RecipeToRecipe"];
        $this->assertNotNull($mapping);
        $this->assertSame(EntityMappingType::transformEntityMappingType, $mapping->mappingType);
        $this->assertSame(RecipeMigrationPolicy::class, $mapping->entityMigrationPolicyClassName, "a mapping model must be able to declare the policy that migrates the entity");
        $this->assertSame(
            $written->entityMappingsByName["RecipeToRecipe"]?->sourceEntityVersionHash,
            $mapping->sourceEntityVersionHash,
            "the version hash the mapping was authored against must survive, so the pair it applies to stays identifiable",
        );
    }

    public function testValueExpressionsSurviveARoundTrip(): void
    {
        $this->writeMappingModel();

        $read = new MappingModel($this->url());
        $attributeMappings = $read->entityMappingsByName["RecipeToRecipe"]?->attributeMappings;

        $this->assertNotNull($attributeMappings);
        $this->assertSame(1, $attributeMappings->count);
        $attributeMapping = $attributeMappings->first;
        $this->assertNotNull($attributeMapping);
        $this->assertSame("instructions", $attributeMapping->name);
        $this->assertNotNull($attributeMapping->valueExpression, "the value expression must be restored, it is what produces the destination value");
    }

    public function testBothModelsAreRestoredAsUsableModels(): void
    {
        $this->writeMappingModel();

        $read = new MappingModel($this->url());

        $this->assertInstanceOf(ManagedObjectModel::class, $read->sourceModel);
        $this->assertInstanceOf(ManagedObjectModel::class, $read->destinationModel);
        $this->assertNotNull($read->sourceModel->entitiesByName["Recipe"]?->attributesByName["directions"], "the source model must describe the attribute the mapping reads from");
        $this->assertNotNull($read->destinationModel->entitiesByName["Recipe"]?->attributesByName["instructions"], "the destination model must describe the attribute the mapping writes to");
    }

    /**
     * A model carried by a mapping model is rebuilt through ManagedObjectModel::newModel(), so it
     * arrives frozen and fully indexed — the same state a model read from its own file has. Handing
     * back the raw unarchived graph instead would yield an editable model whose indexes were never
     * rebuilt by addEntity().
     */
    public function testRestoredModelsAreFrozenLikeAModelReadFromItsOwnFile(): void
    {
        $this->writeMappingModel();

        $read = new MappingModel($this->url());

        $this->assertFalse($read->sourceModel?->isEditable, "a model out of a mapping model must not be editable");
        $this->assertTrue($read->sourceModel?->isImmutable, "a model out of a mapping model must be immutable");
        $this->assertSame(1, $read->sourceModel?->entityVersionHashesByName->count, "the version-hash index must be rebuilt, not inherited from the archive");
    }

    /**
     * Rebuilding each model runs addEntity(), which registers every entity on its ManagedObject
     * subclass — without that ManagedObject::entity() has nothing to resolve and raises. Loading a
     * mapping model therefore has to make its models' classes usable, exactly as loading a model
     * file does.
     */
    public function testRestoredModelsRegisterTheirEntitiesOnTheManagedObjectClass(): void
    {
        $this->writeMappingModel();

        $read = new MappingModel($this->url());
        $entity = $read->destinationModel?->entitiesByName["Recipe"];

        $this->assertNotNull($entity);
        $this->assertSame($entity, MappingRecipe::entity(), "the class must resolve to the entity the restored model holds");
    }

    public function testAMappingModelWithoutAURLIsEmpty(): void
    {
        $mappingModel = new MappingModel();

        $this->assertSame(0, $mappingModel->entityMappings->count);
        $this->assertNull($mappingModel->sourceModel);
        $this->assertNull($mappingModel->destinationModel);
    }
}
