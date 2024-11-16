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
    public string $name {
        get => $this->propertyDescription->name;
    }
    public bool $isOptional {
        get => $this->propertyDescription->isOptional;
    }
    public bool $isTransient {
        get => $this->propertyDescription->isTransient;
    }
    public bool $isUnique {
        get => $this->entity->indexes->contains(fn(SQLIndex $index, string $key): bool => $index->isUnique && $key === $this->name);
    }
    public bool $isConstrained {
        get => $this->entity->indexes->contains(fn(SQLIndex $index, string $key): bool => $key === $this->name);
    }
    public bool $isReadOnly {
        get => $this->propertyDescription->isReadOnly;
    }
    public PropertyDescriptionType $propertyType {
        get => $this->propertyDescription->propertyType;
    }
    public mixed $minValue {
        get => $this->propertyDescription->minValue;
    }
    public mixed $maxValue {
        get => $this->propertyDescription->maxValue;
    }
    public SQLType $sqlType = SQLType::unknown;
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
