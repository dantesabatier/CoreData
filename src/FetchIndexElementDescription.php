<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:17
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\fatal_error;

/**
 * Description of an Index Element
 */
class FetchIndexElementDescription extends ObjectClass
{
    /** @var FetchIndexElementType $collationType The type of collation that the index element uses, either binary or R-tree. */
    public FetchIndexElementType $collationType = FetchIndexElementType::bTree;
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
     * @param FetchIndexElementType $collationType The type of collation that the index element uses.
     */
    public function __construct(?PropertyDescription $property = null, FetchIndexElementType $collationType = FetchIndexElementType::bTree)
    {
        unset($this->property);
        if ($property) {
            $this->property = $property;
            $this->propertyName = $property->name;
        }
        $this->collationType = $collationType;
    }

    public function __serialize(): array
    {
        $data = ["propertyName" => $this->propertyName];
        if ($this->collationType !== FetchIndexElementType::bTree) {
            $data["collationType"] = $this->collationType;
        }
        if (!$this->isAscending) {
            $data["isAscending"] = $this->isAscending;
        }
        if ($this->isUnique) {
            $data["isUnique"] = $this->isUnique;
        }
        return $data;
    }

    public function __unserialize(array $data): void
    {
        unset($this->property);
        $this->propertyName = $data["propertyName"];
        $this->collationType = $data["collationType"] ?? FetchIndexElementType::bTree;
        $this->isAscending = $data["isAscending"];
        $this->isUnique = $data["isUnique"];
    }

    public function __get(string $name)
    {
        $propertyName = $this->propertyName ?? fatal_error("Property name cannot be null");
        return $this->$name = match ($name) {
            "property" => $this->indexDescription->entity->propertiesByName[$propertyName] ?? fatal_error("Entity \"{$this->indexDescription->entity->name}\" does not contains a property named \"$propertyName\""),
            default => $this->valueForUndefinedKey($name)
        };
    }

    public function validateCollationType(FetchIndexElementType|Number|Nil|int|null &$collationType): bool
    {
        if ($collationType instanceof Value) {
            $collationType = $collationType->value;
        }
        if (is_int($collationType)) {
            $collationType = FetchIndexElementType::from($collationType);
        }
        return true;
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof FetchIndexElementDescription) {
            return $this->property->isEqual($other->property) && $this->collationType === $other->collationType;
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

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = new Dictionary();
        $dictionary["propertyName"] = $this->property->name;
        if ($this->collationType !== FetchIndexElementType::bTree) {
            $dictionary["collationType"] = $this->collationType;
        }
        if (!$this->isAscending) {
            $dictionary["isAscending"] = $this->isAscending;
        }
        if ($this->isUnique) {
            $dictionary["isUnique"] = $this->isUnique;
        }
        return $dictionary;
    }
}
