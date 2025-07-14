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
use Sabatier\Foundation\ObjectClass;
use function Sabatier\Foundation\fatal_error;

/**
 * Description of an Index Element
 */
class FetchIndexElementDescription extends ObjectClass
{
    public FetchIndexDescription $indexDescription;
    /** @var string The specified name in the property description. */
    private(set) string $propertyName = UnknownName;
    /** @var bool A Boolean value that controls whether an index that supports direction is an ascending or descending index. */
    public bool $isAscending = true;
    /** @internal */
    public bool $isUnique = false;
    /** @internal */
    public string $order {
        get {
            if ($this->collationType !== FetchIndexElementType::binary) {
                return $this->isAscending ? "ASC" : "DESC";
            }
            return "";
        }
    }
    private(set) PropertyDescription $property;
    public FetchIndexElementType $collationType = FetchIndexElementType::bTree {
        set(FetchIndexElementType|int $value) {
            if (is_int($value)) {
                $value = FetchIndexElementType::from($value);
            }
            $this->collationType = $value;
        }
    }

    /**
     * Creates an index element description using the specified property description and collation type.
     * @param PropertyDescription $property A property description. This property may also be an {@see ExpressionDescription} that expresses a function.
     * @param FetchIndexElementType $collationType The type of collation that the index element uses, either binary or R-tree.
     */
    public function __construct(PropertyDescription $property, FetchIndexElementType $collationType = FetchIndexElementType::bTree)
    {
        $this->property = $property;
        $this->propertyName = $property->name;
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
        $this->propertyName = $data["propertyName"];
        $this->collationType = $data["collationType"] ?? FetchIndexElementType::bTree;
        $this->isAscending = $data["isAscending"];
        $this->isUnique = $data["isUnique"];
        /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
        $this->property = $this->indexDescription->entity->propertiesByName[$this->propertyName] ?? fatal_error("Entity \"{$this->indexDescription->entity->name}\" does not contains a property named \"$this->propertyName\"");
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof FetchIndexElementDescription) {
            return $this->property->isEqual($other->property) && $this->collationType === $other->collationType;
        }
        return false;
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
