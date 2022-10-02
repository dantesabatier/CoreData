<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 30/06/20
 * Time: 16:49
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Expression;

/**
 * Class ExpressionDescription
 * An object that describes an expression to include with a fetch request.
 * An expression description describes a value that a fetch request returns, which doesn't appear as an attribute or relationship on an entity. For example, expressions can aggregate data, or transform an attribute's value. You add expression descriptions to a fetch request using the {@see FetchRequest::propertiesToFetch} method.
 * Don't add expression descriptions to the properties array of {@see EntityDescription}.
 * @package Sabatier\CoreData
 */
class ExpressionDescription extends PropertyDescription
{
    /** @var Expression|null The expression for the receiver. */
    public ?Expression $expression = null;
    /** @var AttributeType The type of the receiver. */
    public AttributeType $expressionResultType = AttributeType::undefined;

    public function __get(string $name)
    {
        if ($name == 'propertyType') {
            $this->$name = PropertyDescriptionType::expression;
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }
}
