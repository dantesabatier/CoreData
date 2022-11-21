<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:44
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\Expression;

/**
 * A mapping instance that specifies in a model how to map from a property in a source entity to a property in a destination entity.
 */
class PropertyMapping extends ObjectClass
{
    /** @var Dictionary<mixed>|null The user info for the property mapping. */
    public ?Dictionary $userInfo = null;

    /**
     * @param string $name The name of the property in the destination entity for the property mapping.
     * @param Expression|null $valueExpression The value expression for the property mapping. The expression is used to create the value for the destination property.
     */
    public function __construct(public string $name, public ?Expression $valueExpression = null)
    {
    }

    public function isEqual($other): bool
    {
        if ($other instanceof PropertyMapping) {
            return $this->name === $other->name && $this->valueExpression === $other->valueExpression;
        }
        return parent::isEqual($other);
    }

    public function description(): string
    {
        return sprintf("<%s %s %s>", self::class, $this->name, $this->hash());
    }
}
