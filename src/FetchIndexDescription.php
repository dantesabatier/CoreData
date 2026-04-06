<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:14
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;

/**
 * The description of the index.
 */
final class FetchIndexDescription extends ObjectClass
{
    /** @var EntityDescription The entity description for the fetch index description. */
    public EntityDescription $entity;
    // FIXME: not implemented
    /** @var Predicate|null A predicate that selects rows for indexing, if the index is a partial index. */
    public ?Predicate $partialIndexPredicate = null;
    /** @internal */
    public bool $isUnique {
        get => $this->elements->first?->isUnique ?? false;
        set {
            $this->elements->setValueForKey($value, "isUnique");
        }
    }
    /** @internal */
    public bool $isSpatial {
        get => $this->elements->first?->collationType === FetchIndexElementType::rTree;
    }
    /** @internal */
    public bool $isBinary {
        get => $this->elements->first?->collationType === FetchIndexElementType::binary;
    }
    /** @internal */
    public bool $isEditable = true;

    /**
     * Creates a fetch index description using the specified name and element descriptions.
     * @param string $name The name of the fetch index description.
     * @param ArrayClass<FetchIndexElementDescription> $elements An array of fetch index element descriptions. Setting this property to an invalid value throws an exception, such as when the new value includes both R-tree and non R-tree elements.
     */
    public function __construct(public string $name, public ArrayClass $elements = new ArrayClass() {
        set {
            $collationTypes = $value->map(fn(FetchIndexElementDescription $element): FetchIndexElementType => $element->collationType);
            $uniqueCollationTypes = new Set($collationTypes);
            $uniqueCollationTypes->count <= 1 ?: fatal_error("Invalid argument: elements must be of the same collation type");
            $this->elements = $value;
            $this->elements->setValueForKey($this, "indexDescription");
        }
    })
    {
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof FetchIndexDescription) {
            //FIXME: This may not be enough
            return $this->name === $other->name;
        }
        return false;
    }
}
