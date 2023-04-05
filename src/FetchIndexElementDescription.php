<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:17
 */

namespace Sabatier\CoreData;

use InvalidArgumentException;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;

/**
 * Description of an Index Element
 */
class FetchIndexElementDescription extends ObjectClass
{
    /** @var PropertyDescription A property description. This property may also be an {@see ExpressionDescription} that expresses a function. */
    public readonly PropertyDescription $property;
    public FetchIndexDescription $indexDescription;
    /** @var string|null The specified name in the property description. */
    public ?string $propertyName = null;
    /** @var bool A Boolean value that controls whether an index that supports direction is an ascending or descending index. */
    public bool $isAscending = true;
    public bool $isUnique = false;

    /**
     * Creates an index element description using the specified property description and collation type.
     * @param PropertyDescription|null $property A property description.
     * @param FetchIndexElementType $collationType The type of collation that the index element uses, either binary or R-tree.
     */
    public function __construct(?PropertyDescription $property = null, public FetchIndexElementType $collationType = FetchIndexElementType::bTree)
    {
        unset($this->property);
        if ($property) {
            $this->property = $property;
        }
    }

    public function __get(string $name)
    {
        $propertyName = $this->propertyName ?? throw new InvalidArgumentException("Property name cannot be null");
        return $this->$name = match ($name) {
            "property" => $this->indexDescription->entity->propertiesByName[$propertyName] ?? throw new InvalidArgumentException(sprintf("Property \"%s\" does not exists", $propertyName)),
            default => $this->valueForUndefinedKey($name)
        };
    }

    public function isEqual(mixed $other): bool
    {
        if ($other instanceof FetchIndexElementDescription) {
            return $this->property->isEqual($other->property) && $this->collationType == $other->collationType;
        }
        return false;
    }

    /** @internal */
    public function order(): string
    {
        if ($this->collationType !== FetchIndexElementType::binary) {
            return $this->isAscending ? "ASC" : "DESC";
        }
        return "";
    }

    public function jsonSerialize(): Dictionary
    {
        /** @var Dictionary $dictionary */
        $dictionary = new Dictionary();
        $dictionary["propertyName"] = $this->property->name;
        if ($this->collationType !== FetchIndexElementType::bTree) {
            $dictionary["collationType"] = $this->collationType->value;
        }
        return $dictionary;
    }
}
