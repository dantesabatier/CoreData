<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:17
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ObjectClass;

/**
 * Description of an Index Element
 */
final class FetchIndexElementDescription extends ObjectClass
{
    public FetchIndexDescription $indexDescription;
    /** @var string The specified name in the property description. */
    private(set) string $propertyName = UnknownName;
    /** @var bool A Boolean value that controls whether an index that supports a direction is an ascending or descending index. */
    public bool $isAscending = true;
    /** @internal */
    public bool $isUnique = false;
    /** @internal */
    public string $order {
        get => $this->collationType !== FetchIndexElementType::binary ? ($this->isAscending ? "ASC" : "DESC") : "";
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

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof FetchIndexElementDescription) {
            return $this->property->isEqual($other->property) && $this->collationType === $other->collationType;
        }
        return false;
    }
}
