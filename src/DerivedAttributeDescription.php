<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/07/20
 * Time: 09:36
 */
namespace Sabatier\CoreData;

use Override;
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
    #[Override]
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
    public bool $usesKeyValueCoding {
        get => $this->compatibility && $this->compatibility->usesKeyValueCoding;
    }
    /** @internal */
    public bool $usesKeyValueOperator {
        get => $this->compatibility && $this->compatibility->usesKeyValueOperator;
    }
    /** @internal */
    public bool $isRuntimeOnly {
        get => $this->compatibility && $this->compatibility->isRuntimeOnly;
    }
}
