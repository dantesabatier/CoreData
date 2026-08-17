<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\EntityMapping;
use Sabatier\CoreData\EntityMappingType;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MappingModel;
use Sabatier\CoreData\PropertyMapping;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\MappingModelFileExtension;

/**
 * Locating a mapping model in a bundle.
 *
 * A mapping model is found by version information, not by file name: it declares the entity version
 * hashes it was authored against, and those hashes are what identify the pair of model versions it
 * applies to. A bundle can therefore hold several mapping models and the right one is selected for
 * whichever migration is being performed.
 */
final class MappingModelDiscoveryTest extends TestCase
{
    private string $bundlePath;

    protected function setUp(): void
    {
        $this->bundlePath = sys_get_temp_dir() . "/" . uniqid("bundle", true);
        mkdir($this->bundlePath, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->bundlePath . "/*") ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->bundlePath)) {
            rmdir($this->bundlePath);
        }
    }

    private function bundle(): Bundle
    {
        return Bundle::bundleWithURL(new URL("file:///" . str_replace("\\", "/", $this->bundlePath)));
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
        $entity = new EntityDescription();
        $entity->name = "Note";
        $entity->properties = new ArrayClass($attributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private static function v1(): ManagedObjectModel
    {
        return self::model([self::attribute("body", AttributeType::string)]);
    }

    private static function v2(): ManagedObjectModel
    {
        return self::model([self::attribute("text", AttributeType::string, renamingIdentifier: "body")]);
    }

    private static function v3(): ManagedObjectModel
    {
        return self::model([
            self::attribute("text", AttributeType::string, renamingIdentifier: "body"),
            self::attribute("pinned", AttributeType::boolean),
        ]);
    }

    /**
     * Writes a mapping model for a model pair into the bundle under $name, and returns it.
     */
    private function writeMappingModel(string $name, ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel, string $destinationAttributeName): MappingModel
    {
        $mapping = new EntityMapping();
        $mapping->sourceEntityName = "Note";
        $mapping->destinationEntityName = "Note";
        $mapping->sourceEntityVersionHash = $sourceModel->entitiesByName["Note"]?->versionHash;
        $mapping->destinationEntityVersionHash = $destinationModel->entitiesByName["Note"]?->versionHash;
        $mapping->mappingType = EntityMappingType::transformEntityMappingType;
        $mapping->attributeMappings = new ArrayClass([
            new PropertyMapping($destinationAttributeName, Expression::expressionWithFormat("\$source.body")),
        ]);

        $mappingModel = new MappingModel();
        $mappingModel->sourceModel = $sourceModel;
        $mappingModel->destinationModel = $destinationModel;
        $mappingModel->entityMappings = new ArrayClass([$mapping]);

        file_put_contents($this->bundlePath . "/" . $name . "." . MappingModelFileExtension, KeyedArchiver::archivedData($mappingModel));
        return $mappingModel;
    }

    public function testTheMappingModelForTheRequestedPairIsFound(): void
    {
        $this->writeMappingModel("first", self::v1(), self::v2(), "text");

        $found = MappingModel::mappingModel(new ArrayClass([$this->bundle()]), self::v1(), self::v2());

        $this->assertNotNull($found, "a mapping model whose hashes match both models must be found");
        $this->assertSame(["NoteToNote"], $found->entityMappingsByName->keys->array);
    }

    /**
     * The file name carries no meaning: with two candidates in the bundle, the hashes are what pick
     * the one that maps the pair being migrated.
     */
    public function testTheCorrectMappingModelIsSelectedAmongSeveral(): void
    {
        $this->writeMappingModel("zeta", self::v2(), self::v3(), "pinned");
        $this->writeMappingModel("alpha", self::v1(), self::v2(), "text");

        $found = MappingModel::mappingModel(new ArrayClass([$this->bundle()]), self::v2(), self::v3());

        $this->assertNotNull($found);
        $attributeMapping = $found->entityMappingsByName["NoteToNote"]?->attributeMappings?->first;
        $this->assertSame("pinned", $attributeMapping?->name, "the mapping model for the v2 -> v3 pair must be the one selected");
    }

    public function testNoMappingModelIsFoundForAnUnmappedPair(): void
    {
        $this->writeMappingModel("first", self::v1(), self::v2(), "text");

        $this->assertNull(MappingModel::mappingModel(new ArrayClass([$this->bundle()]), self::v1(), self::v3()));
    }

    public function testNoMappingModelIsFoundInAnEmptyBundle(): void
    {
        $this->assertNull(MappingModel::mappingModel(new ArrayClass([$this->bundle()]), self::v1(), self::v2()));
    }
}
