<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyedArchiver;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;

/**
 * A description of a property of a Core Data entity.
 */
abstract class PropertyDescription extends ObjectClass
{
    /** @var string The name of the receiver. A property name cannot be the same as any no-parameter method name of Object or ManagedObject. */
    public string $name = UnknownName;
    /** @var EntityDescription The entity description of the receiver. */
    public EntityDescription $entity;
    /** @var Dictionary<mixed>|null The user info dictionary of the receiver. */
    public ?Dictionary $userInfo = null;
    /** @var bool A Boolean value that indicates whether the receiver is optional. The optionality flag specifies whether a property's value can be null before an object can be saved to a persistent store. */
    public bool $isOptional = true;
    /** @var bool A Boolean value that indicates whether the receiver is transient. The transient flag specifies whether a property's value is ignored when an object is saved to a persistent store. Transient properties are not saved to the persistent store, but are still managed for undo, redo, validation, and so on. */
    public bool $isTransient = false;
    /** @var ArrayClass<Predicate> The validation predicates of the receiver. */
    private(set) ArrayClass $validationPredicates {
        get {
            if (isset($this->validationPredicates)) {
                return $this->validationPredicates;
            }
            /** @var ArrayClass<Predicate> $validationPredicates */
            $validationPredicates = new ArrayClass();
            $minValue = $this->minValue;
            if (is_numeric($minValue)) {
                $validationPredicates->append(new ComparisonPredicate(Expression::expressionForKeyPath($this->name), Expression::expressionForConstantValue($minValue), PredicateOperatorType::greaterThanOrEqualTo));
            }
            $maxValue = $this->maxValue;
            if (is_numeric($maxValue)) {
                $validationPredicates->append(new ComparisonPredicate(Expression::expressionForKeyPath($this->name), Expression::expressionForConstantValue($maxValue), PredicateOperatorType::lessThanOrEqualTo));
            }
            $regex = $this->regex;
            if (is_string($regex)) {
                $validationPredicates->append(new ComparisonPredicate(Expression::expressionForKeyPath($this->name), Expression::expressionForConstantValue($regex), PredicateOperatorType::matches));
            }
            return $this->validationPredicates = $validationPredicates;
        }
    }
    /** @var ArrayClass<string> The error strings associated with the receiver's validation predicates. */
    private(set) ArrayClass $validationWarnings {
        get => $this->validationWarnings ??= $this->validationPredicates->map(fn(Predicate $predicate): string => $predicate->predicateFormat);
    }
    /** @var string The version hash for the receiver. The version hash is used to uniquely identify a property based on its configuration. The version hash uses only values which affect the persistence of data and the user-defined {@see versionHashModifier} value. (The values which affect persistence are the name of the property, and the flags for isOptional, isTransient, and isReadOnly.) This value is stored as part of the version information in the metadata for stores, as well as a definition of a property involved in a {@see PropertyMapping} object. */
    public string $versionHash {
        get {
            $this->versionHashInStyle($hash, VersionHashStyle::default);
            return $hash;
        }
    }
    /** @var string|null The version hash modifier for the receiver. This value is included in the version hash for the property. You use it to mark or denote a property as being a different “version” than another even if all the values which affect persistence are equal. (Such a difference is important in cases where the attributes of a property are unchanged but the format or content of its data are changed.) */
    public ?string $versionHashModifier = null;
    /** @var string The renaming identifier for the receiver. This is used to resolve naming conflicts between models. When creating an entity mapping between entities in two managed object models, a source entity property and a destination entity property that share the same identifier indicate that a property mapping should be configured to migrate from the source to the destination. If unset, the identifier will return the property's name. */
    public string $renamingIdentifier {
        get => $this->renamingIdentifier ??= $this->name;
    }
    public bool $isSensitive = false;
    /** @internal */
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::private;
    /** @internal */
    public bool $isEditable = true;
    /** @internal */
    public bool $isReadOnly = false;
    /** @internal */
    public mixed $minValue = null;
    /** @internal */
    public mixed $maxValue = null;
    /** @internal */
    public ?string $regex = null;
    #[Override]
    public string $description {
        get => sprintf("(<%s: %s>), name %s, isOptional %s, isTransient %s, entity %s renamingIdentifier %s, validation predicates %s, warnings %s", $this->class, $this->hash, $this->name, human_readable_value($this->isOptional), human_readable_value($this->isTransient), $this->entity->name, $this->renamingIdentifier, $this->validationPredicates->description, $this->validationWarnings->description);
    }

    private function throwIfNotEditable(): void
    {
        $this->isEditable ?: fatal_error("$this->debugDescription cannot be edited before initialization");
    }

    /**
     * @param ArrayClass<Predicate>|null $validationPredicates An array containing the validation predicates for the receiver.
     * @param ArrayClass<string>|null $validationWarnings An array containing the validation warnings for the receiver.
     * The validationPredicates and validationWarnings arrays should contain the same number of elements, and corresponding elements should appear at the same index in each array.
     * Instead of implementing individual validation methods, you can use this method to provide a list of predicates that are evaluated against the managed objects and a list of corresponding error messages (which can be localized).
     */
    public function setValidationPredicates(?ArrayClass $validationPredicates, ?ArrayClass $validationWarnings): void
    {
        $this->throwIfNotEditable();
        $this->validationPredicates = $validationPredicates ?? new ArrayClass();
        $this->validationWarnings = $validationWarnings ?? new ArrayClass();
    }

    /** @internal */
    public function versionHashInStyle(?string &$out, VersionHashStyle $style): void
    {
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = new Dictionary();
        $dictionary["name"] = $this->name;
        if (!$this->isOptional) {
            $dictionary["isOptional"] = $this->isOptional;
        }
        if ($this->isTransient) {
            $dictionary["isTransient"] = $this->isTransient;
        }
        if ($this->isSensitive) {
            $dictionary["isSensitive"] = $this->isSensitive;
        }
        if ($this->versionHashModifier) {
            $dictionary["versionHashModifier"] = $this->versionHashModifier;
        }
        $minValue = $this->minValue;
        if ($minValue !== null) {
            $dictionary["minValue"] = $minValue;
        }
        $maxValue = $this->maxValue;
        if ($maxValue !== null) {
            $dictionary["maxValue"] = $maxValue;
        }
        if ($this->regex) {
            $dictionary["regex"] = $this->regex;
        }
        $out = KeyedArchiver::archivedData($dictionary);
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof PropertyDescription) {
            if ($this->isEditable || $other->isEditable) {
                return $this->renamingIdentifier === $other->renamingIdentifier;
            }
            return $this->entity->isKindOf($other->entity) && $this->renamingIdentifier === $other->renamingIdentifier;
        }
        return false;
    }
}
