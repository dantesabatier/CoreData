<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/07/20
 * Time: 09:36
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Expression;

/**
 * A description of an attribute of a Core Data entity that derives its value from one or more other properties.
 *
 * Use derived attributes to optimize fetch performance; for example,
 * Create a derived $searchName attribute to reflect a name attribute with the case and diacritics removed for more efficient comparison.
 * Create a derived $relationshipCount attribute to reflect the number of objects in a relationship and avoid having to do a join.
 */
final class DerivedAttributeDescription extends AttributeDescription
{
    /** @internal */
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::derivedAttribute;
    /** @var Expression|null An expression for generating derived data. */
    public ?Expression $derivationExpression = null;
    private bool $isCompatibilityResolved = false;
    private ?DerivationSchemaCompatibility $compatibility {
        get {
            if ($this->isCompatibilityResolved) {
                return $this->compatibility;
            }
            $this->isCompatibilityResolved = true;
            if (!($derivationExpression = $this->derivationExpression)) {
                return $this->compatibility = null;
            }
            return $this->compatibility = new DerivationSchemaCompatibility($derivationExpression);
        }
    }
    /** @internal */
    public bool $isDeterministic {
        get => $this->compatibility && $this->compatibility->isDeterministic;
    }
    /** @internal */
    public bool $usesKVC {
        get => $this->compatibility && $this->compatibility->usesKVC;
    }
    /** @internal */
    public bool $usesKVO {
        get => $this->compatibility && $this->compatibility->usesKVO;
    }
    /** @internal */
    public bool $isRuntimeOnly {
        get => $this->compatibility && $this->compatibility->isRuntimeOnly;
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        $dictionary = parent::jsonSerialize();
        if ($derivationExpression = $this->derivationExpression) {
            $dictionary["derivationExpressionFormat"] = (string)$derivationExpression;
        }
        return $dictionary;
    }
}
