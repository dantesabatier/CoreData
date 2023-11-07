<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:39
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ObjectClass;

/** @internal */
abstract class SQLProperty extends ObjectClass
{
    public string $name;
    public readonly bool $isOptional;
    public PropertyDescriptionType $propertyType;
    public SQLType $sqlType = SQLType::unknown;
    public int $fetchIndex;
    public int $slot;
    public readonly bool $isUnique;
    public readonly bool $isConstrained;
    public bool $allowAliasing = false;
    public readonly mixed $minValue;
    public readonly mixed $maxValue;

    public function __construct(public SQLEntity $entity, public PropertyDescription $propertyDescription)
    {
        unset($this->name);
        unset($this->isOptional);
        unset($this->isUnique);
        unset($this->isConstrained);
        unset($this->propertyType);
        unset($this->sqlType);
        unset($this->minValue);
        unset($this->maxValue);
    }

    public function __get(string $name)
    {
        return $this->$name = match ($name) {
            "name" => $this->propertyDescription->name,
            "isOptional" => $this->propertyDescription->isOptional,
            "propertyType" => $this->propertyDescription->propertyType,
            "sqlType" => SQLType::unknown,
            "isUnique" => $this->entity->indexes->contains(fn(SQLIndex $index, string $key): bool => $index->isUnique && $key === $this->name),
            "isConstrained" => $this->entity->indexes->contains(fn(SQLIndex $index, string $key): bool => $key === $this->name),
            "minValue" => $this->propertyDescription->minValue,
            "maxValue" => $this->propertyDescription->maxValue,
            default => $this->valueForUndefinedKey($name)
        };
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == "name" || $name == "isOptional" || $name == "isUnique" || $name == "isConstrained" || $name == "propertyType" || $name == "sqlType" || $name == "minValue" || $name == "maxValue") {
            $this->$name = $value;
        } else {
            $this->setValueForUndefinedKey($value, $name);
        }
    }

    public function description(): string
    {
        return sprintf("<%s %s>", $this->name, $this->hash());
    }

    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLProperty) {
            return $this->propertyDescription->isEqual($other->propertyDescription);
        }
        return false;
    }
}
