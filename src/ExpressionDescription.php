<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 30/06/20
 * Time: 16:49
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\human_readable_value;

/**
 * An object that describes an expression to include with a fetch request.
 *
 * An expression description describes a value that a fetch request returns, which doesn't appear as an attribute or relationship on an entity. For example, expressions can aggregate data, or transform an attribute's value. You add expression descriptions to a fetch request using the {@see FetchRequest::propertiesToFetch} method.
 * Don't add expression descriptions to the properties array of {@see EntityDescription}.
 */
class ExpressionDescription extends PropertyDescription
{
    public string $description {
        get => sprintf("%s, expression %s", parent::$description->get(), human_readable_value($this->expression));
    }
    /** @internal */
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::expression;
    /** @var Expression|null The expression for the receiver. */
    public ?Expression $expression = null;
    /** @var AttributeType The attribute type of the expression’s result. */
    public AttributeType $resultType = AttributeType::undefined;

    public function validateResultType(AttributeType|Number|Nil|int|null &$resultType): bool
    {
        if ($resultType instanceof Value) {
            $resultType = $resultType->value;
        }
        if (is_int($resultType)) {
            $resultType = AttributeType::from($resultType);
        }
        return true;
    }
}
