<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:39
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ObjectClass;

/** @internal */
abstract class SQLProperty extends ObjectClass
{
    protected(set) string $name {
        get => $this->name ??= $this->propertyDescription->name;
    }
    protected(set) bool $isOptional {
        get => $this->isOptional ??= $this->propertyDescription->isOptional;
    }
    protected(set) bool $isTransient {
        get => $this->isTransient ??= $this->propertyDescription->isTransient;
    }
    protected(set) bool $isUnique {
        get => $this->isUnique ??= $this->entity->indexes->contains(fn(SQLIndex $index, string $key): bool => $index->isUnique && $key === $this->name);
    }
    protected(set) bool $isConstrained {
        get => $this->isConstrained ??= $this->entity->indexes->contains(fn(SQLIndex $index, string $key): bool => $key === $this->name);
    }
    protected(set) bool $isReadOnly {
        get => $this->isReadOnly ??= $this->propertyDescription->isReadOnly;
    }
    protected(set) PropertyDescriptionType $propertyType {
        get => $this->propertyType ??= $this->propertyDescription->propertyType;
    }
    protected(set) mixed $minValue {
        get => $this->minValue ??= $this->propertyDescription->minValue;
    }
    protected(set) mixed $maxValue {
        get => $this->maxValue ??= $this->propertyDescription->maxValue;
    }
    protected(set) SQLType $sqlType = SQLType::unknown;
    public string $description {
        get => sprintf("<%s %s>", $this->name, $this->hash);
    }

    public function __construct(public SQLEntity $entity, public PropertyDescription $propertyDescription)
    {
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLProperty) {
            return $this->name === $other->name;
        }
        return false;
    }
}
