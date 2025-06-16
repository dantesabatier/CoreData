<?php

namespace Sabatier\CoreData;

use InvalidArgumentException;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\KeyedUnarchiver;

/**
 * A description of an attribute that derives its value by composing other attributes.
 *
 * Composite attributes enable you to define and store complex data types, and then query, index, and apply constraints to those types. Model classes use dictionaries to represent those composites in-memory, where each dictionary contains keys corresponding to the names of the underlying attributes. You may use composite attributes anywhere you use standard attributes. You can even nest composites inside other composites to create complex object hierarchies without additional model classes.
 * Composite attributes are available only to persistent stores that you configure with the SQL store type.
 */
class CompositeAttributeDescription extends AttributeDescription
{
    /** @internal */
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::compositeAttribute;
    /** @var ArrayClass<AttributeDescription> The composed attribute descriptions. */
    public ArrayClass $elements {
        get => $this->elements ??= new ArrayClass();
        set {
            $value->allSatisfy(fn(mixed $e): bool => $e instanceof AttributeDescription) ?: throw new InvalidArgumentException();
            $this->elements = $value;
        }
    }

    #[Override]
    public function versionHashInStyle(?string &$out, VersionHashStyle $style): void
    {
        parent::versionHashInStyle($data, $style);
        /** @var Dictionary $dictionary */
        $dictionary = KeyedUnarchiver::unarchiveTopLevelObjectWithData((string)$data);
        $dictionary["elements"] = $this->elements->map(function (AttributeDescription $element) use ($style): Dictionary {
            $element->versionHashInStyle($data, $style);
            return KeyedUnarchiver::unarchiveTopLevelObjectWithData((string)$data);
        });
        $out = KeyedArchiver::archivedData($dictionary);
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        $dictionary = parent::jsonSerialize();
        $elements = $this->elements->map(fn(AttributeDescription $element): Dictionary => $element->jsonSerialize());
        if (!$elements->isEmpty) {
            $dictionary["elements"] = $elements;
        }
        return $dictionary;
    }
}
