<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\Value;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\human_readable_value;

/**
 * A description of an attribute of a Core Data entity.
 */
class AttributeDescription extends PropertyDescription
{
    /** @internal */
    #[Override]
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::attribute;

    /** @var AttributeType The attribute's type. */
    public AttributeType $type = AttributeType::undefined {
        set(AttributeType|int $value) {
            if (is_int($value)) {
                $value = AttributeType::from($value);
            }
            $this->type = $value;
        }
    }
    /** @var mixed The default value of the attribute. */
    public mixed $defaultValue = null {
        get {
            $defaultValue = $this->defaultValue;
            if ($defaultValue !== null) {
                ManagedObject::coerceValue($defaultValue, $this);
            }
            return $defaultValue;
        }
        set => new Value($value)->value;
    }
    /** @var string|null The name of the class used to represent the attribute. */
    public ?string $attributeValueClassName = null {
        get => $this->attributeValueClassName ??= match ($this->type) {
            AttributeType::date => Date::class,
            AttributeType::uuid => UUID::class,
            AttributeType::uri => URL::class,
            AttributeType::objectID => ManagedObjectID::class,
            default => null,
        };
    }
    /** @var string|null The name of the transformer used to transform the attribute value. The attribute must be of type {@see AttributeType::transformable}. The transformer must output data from {@see ValueTransformer::transformedValue()} and must allow reverse transformations. If this value is null, Core Data uses a default a transformer to archive and unarchive the attribute value. */
    public ?string $valueTransformerName = null;
    /** @var bool A Boolean value that indicates whether the attribute allows external binary storage. If this value is true, the corresponding attribute may be stored in a file external to the persistent store itself. */
    public bool $allowsExternalBinaryDataStorage = false;
    /** @var bool A Boolean value that indicates whether the attribute records its value in the persistent history transaction for a managed object's deletion. */
    public bool $preservesValueInHistoryOnDeletion = false;
    #[Override]
    public string $description {
        get => sprintf("%s, type %s", parent::$description::get(), human_readable_value($this->type));
    }
    /** @internal */
    public ?CompositeAttributeDescription $superCompositeAttribute = null;

    #[Override]
    public function versionHashInStyle(?string &$out, VersionHashStyle $style): void
    {
        parent::versionHashInStyle($data, $style);
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = KeyedUnarchiver::unarchiveTopLevelObjectWithData((string)$data);
        if ($this->type !== AttributeType::undefined) {
            $dictionary["type"] = $this->type->value;
        }
        $out = KeyedArchiver::archivedData($dictionary);
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        $dictionary = parent::jsonSerialize();
        if ($this->type !== AttributeType::undefined) {
            $dictionary["type"] = $this->type;
        }
        $defaultValue = $this->defaultValue;
        if ($defaultValue !== null) {
            $dictionary["defaultValue"] = $defaultValue;
        }
        return $dictionary;
    }
}
