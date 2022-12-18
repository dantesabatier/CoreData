<?php

namespace Sabatier\CoreData;

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
 *
 * @property mixed $defaultValue The default value of the attribute.
 */
class AttributeDescription extends PropertyDescription
{
    /** @var AttributeType The attribute's type. */
    public AttributeType $type = AttributeType::undefined;
    protected mixed $defaultValue = null;
    /** @var string|null The name of the class used to represent the attribute. */
    public ?string $attributeValueClassName = null;
    /** @var string|null The name of the transformer used to transform the attribute value. The attribute must be of type {@see AttributeType::transformable}. The transformer must output data from {@see ValueTransformer::transformedValue()} and must allow reverse transformations. If this value is nil, Core Data uses a default a transformer to archive and unarchive the attribute value. */
    public ?string $valueTransformerName = null;
    /** @var bool A Boolean value that indicates whether the attribute allows external binary storage. */
    public bool $allowsExternalBinaryDataStorage = false;
    /** @var bool A Boolean value that indicates whether the attribute records its value in the persistent history transaction for a managed object's deletion. */
    public bool $preservesValueInHistoryOnDeletion = false;

    public function __construct()
    {
        parent::__construct();
        unset($this->attributeValueClassName);
    }

    public function __get(string $name)
    {
        if ($name == "attributeValueClassName") {
            $this->$name = match ($this->type) {
                AttributeType::date => Date::class,
                AttributeType::uuid => UUID::class,
                AttributeType::uri => URL::class,
                AttributeType::objectID => ManagedObjectID::class,
                default => null,
            };
            return $this->$name;
        } elseif ($name == "propertyType") {
            $this->$name = PropertyDescriptionType::attribute;
            return $this->$name;
        } elseif ($name == "defaultValue") {
            if ($this->$name !== null) {
                return ManagedObject::coercedValue($this->$name, $this->type, $this->attributeValueClassName, $this->valueTransformerName);
            }
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == "attributeValueClassName") {
            $this->$name = $value;
        } elseif ($name == "defaultValue") {
            $this->$name = (new Value($value))->value;
        } else {
            parent::__set($name, $value);
        }
    }

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

    public function description(): string
    {
        return sprintf("%s, type %s", parent::description(), human_readable_value($this->type));
    }

    public function jsonSerialize(): Dictionary
    {
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = parent::jsonSerialize();
        if ($this->type !== AttributeType::undefined) {
            $dictionary["type"] = $this->type->value;
        }
        $defaultValue = $this->defaultValue;
        if ($defaultValue !== null) {
            $dictionary["defaultValue"] = $defaultValue;
        }
        return $dictionary;
    }
}
