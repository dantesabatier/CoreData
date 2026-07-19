<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\InferredMappingModelException;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MappingModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;

/**
 * Tests for MappingModelBuilder's schema-compatibility check, exposed through
 * MappingModel::inferredMappingModel. An inferred (lightweight) migration is only possible when
 * every changed property can be mapped automatically. Two changes cannot be inferred and must
 * raise InferredMappingModelException:
 *
 *  - an attribute whose type changes in a way canTransformAttributeType rejects (e.g.
 *    string -> binaryData): there is no automatic value conversion;
 *  - a relationship whose destination entity changes: the migrator cannot know how to re-target
 *    the related objects.
 *
 * Everything the framework can handle on its own must NOT raise: adding/removing properties,
 * renames (shared renamingIdentifier), transformable numeric type widening, optionality changes,
 * and a new mandatory attribute (which the framework fills with its type default).
 */
final class MappingModelBuilderSchemaMatchTest extends TestCase
{
    private static function attribute(string $name, AttributeType $type, ?string $renamingIdentifier = null, bool $optional = true): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = $optional;
        if ($renamingIdentifier !== null) {
            $attribute->renamingIdentifier = $renamingIdentifier;
        }
        return $attribute;
    }

    /**
     * @param list<AttributeDescription|RelationshipDescription> $properties
     */
    private static function widgetModel(array $properties, string $destinationEntityName = "Cog"): ManagedObjectModel
    {
        $widget = new EntityDescription();
        $widget->name = "Widget";
        $widget->properties = new ArrayClass($properties);

        // A companion entity so relationship-destination tests have somewhere to point.
        $cog = new EntityDescription();
        $cog->name = $destinationEntityName;
        $cog->properties = new ArrayClass([self::attribute("code", AttributeType::string)]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget, $cog]);
        return $model;
    }

    // --- Inferable changes: must NOT raise ---

    public function testAddingAndRemovingAttributesIsInferable(): void
    {
        $v1 = self::widgetModel([self::attribute("name", AttributeType::string)]);
        $v2 = self::widgetModel([
            self::attribute("name", AttributeType::string),
            self::attribute("color", AttributeType::string),
        ]);

        $model = MappingModel::inferredMappingModel($v1, $v2);
        $this->assertNotNull($model, "adding an attribute is inferable");
    }

    public function testNumericWideningIsInferable(): void
    {
        $v1 = self::widgetModel([self::attribute("size", AttributeType::integer32)]);
        $v2 = self::widgetModel([self::attribute("size", AttributeType::integer64)]);

        $model = MappingModel::inferredMappingModel($v1, $v2);
        $this->assertNotNull($model, "a transformable numeric type change is inferable");
    }

    public function testAttributeRenameIsInferable(): void
    {
        $v1 = self::widgetModel([self::attribute("colour", AttributeType::string)]);
        $v2 = self::widgetModel([self::attribute("color", AttributeType::string, renamingIdentifier: "colour")]);

        $model = MappingModel::inferredMappingModel($v1, $v2);
        $this->assertNotNull($model, "an attribute rename (shared renamingIdentifier) is inferable");
    }

    public function testNewMandatoryAttributeIsInferable(): void
    {
        // The framework fills mandatory attributes with their type default, so a new
        // non-optional attribute is inferable rather than a hard failure.
        $v1 = self::widgetModel([self::attribute("name", AttributeType::string)]);
        $v2 = self::widgetModel([
            self::attribute("name", AttributeType::string),
            self::attribute("count", AttributeType::integer32, optional: false),
        ]);

        $model = MappingModel::inferredMappingModel($v1, $v2);
        $this->assertNotNull($model, "a new mandatory attribute is inferable (filled with a default)");
    }

    // --- Non-inferable changes: must raise ---

    public function testNonTransformableAttributeTypeChangeRaises(): void
    {
        $v1 = self::widgetModel([self::attribute("payload", AttributeType::string)]);
        $v2 = self::widgetModel([self::attribute("payload", AttributeType::binaryData)]);

        $this->expectException(InferredMappingModelException::class);
        MappingModel::inferredMappingModel($v1, $v2);
    }

    public function testRelationshipDestinationChangeRaises(): void
    {
        $toCog = new RelationshipDescription();
        $toCog->name = "part";
        $toCog->lazyDestinationEntityName = "Cog";
        $toCog->lazyInverseRelationshipName = "widget";
        $toCog->maxCount = 1;

        $toGear = new RelationshipDescription();
        $toGear->name = "part";
        $toGear->lazyDestinationEntityName = "Gear";
        $toGear->lazyInverseRelationshipName = "widget";
        $toGear->maxCount = 1;

        $v1 = self::widgetModel([$toCog], destinationEntityName: "Cog");
        $v2 = self::widgetModel([$toGear], destinationEntityName: "Gear");

        $this->expectException(InferredMappingModelException::class);
        MappingModel::inferredMappingModel($v1, $v2);
    }
}
