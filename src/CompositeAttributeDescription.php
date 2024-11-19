<?php

namespace Sabatier\CoreData;

use InvalidArgumentException;
use Sabatier\Foundation\ArrayClass;

/**
 * A description of an attribute that derives its value by composing other attributes.
 *
 * Composite attributes enable you to define and store complex data types, and then query, index, and apply constraints to those types. Model classes use dictionaries to represent those composites in-memory, where each dictionary contains keys corresponding to the names of the underlying attributes. You may use composite attributes anywhere you use standard attributes. You can even nest composites inside other composites to create complex object hierarchies without additional model classes.
 * Composite attributes are available only to persistent stores that you configure with the sql store type.
 */
class CompositeAttributeDescription extends AttributeDescription
{
    /** @internal */
    public PropertyDescriptionType $propertyType {
        get => PropertyDescriptionType::compositeAttribute;
    }
    /** @var ArrayClass<AttributeDescription> The composed attribute descriptions. */
    public ArrayClass $elements {
        set {
            $value->allSatisfy(fn(mixed $e): bool => $e instanceof AttributeDescription) ?: throw new InvalidArgumentException();
            $this->elements = $value;
        }
    }

    public function __construct()
    {
        $this->elements = new ArrayClass();
    }
}
