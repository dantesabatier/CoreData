<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/07/20
 * Time: 09:36
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Expression;

/**
 * A description of an attribute of a Core Data entity that derives its value from one or more other properties.
 *
 * Use derived attributes to optimize fetch performance; for example:
 * Create a derived searchName attribute to reflect a name attribute with case and diacritics removed for more efficient comparison.
 * Create a derived relationshipCount attribute to reflect the number of objects in a relationship and avoid having to do a join.
 */
class DerivedAttributeDescription extends AttributeDescription
{
    /** @var Expression|null An expression for generating derived data. */
    public ?Expression $derivationExpression = null;

    public function __get(string $name)
    {
        if ($name == "propertyType") {
            $this->$name = PropertyDescriptionType::derivedAttribute;
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }

    public function jsonSerialize(): Dictionary
    {
        $dictionary = parent::jsonSerialize();
        if ($derivationExpression = $this->derivationExpression) {
            $dictionary["derivationExpressionFormat"] = (string)$derivationExpression;
        }
        return $dictionary;
    }
}
