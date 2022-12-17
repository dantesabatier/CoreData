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
    public bool $isUnique = false;
    public bool $isConstrained = false;
    public bool $allowAliasing = false;

    public function __construct(public SQLEntity $entity, public PropertyDescription $propertyDescription)
    {
        unset($this->name);
        unset($this->isOptional);
        unset($this->propertyType);
        unset($this->sqlType);
    }

    public function __get(string $name)
    {
        return $this->$name = match ($name) {
            "name" => $this->propertyDescription->name,
            "isOptional" => $this->propertyDescription->isOptional,
            "propertyType" => $this->propertyDescription->propertyType,
            "sqlType" => SQLType::unknown,
            default => $this->valueForUndefinedKey($name)
        };
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == "name" || $name == "isOptional" || $name == "propertyType" || $name == "sqlType") {
            $this->$name = $value;
        } else {
            $this->setValueForUndefinedKey($value, $name);
        }
    }

    public function description(): string
    {
        return sprintf("<%s %s>", $this->name, $this->hash());
    }
}
