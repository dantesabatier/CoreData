<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 14:32
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;

/** @internal */
class SQLAttribute extends SQLColumn
{
    public readonly AttributeDescription $attributeDescription;
    /** @var Set<string> */
    public readonly Set $triggerKeys;
    public readonly bool $isBackedByTrigger;
    public readonly bool $isDerivedAttribute;
    public readonly ?Expression $derivationExpression;

    public function __construct(SQLEntity $entity, AttributeDescription $attributeDescription)
    {
        parent::__construct($entity, $attributeDescription);
        unset($this->attributeDescription);
        unset($this->triggerKeys);
        unset($this->isBackedByTrigger);
        unset($this->isDerivedAttribute);
        unset($this->derivationExpression);
    }

    #[Override]
    public function __get(string $name)
    {
        if ($name == "attributeDescription") {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->$name = $this->propertyDescription;
            return $this->$name;
        } elseif ($name == "sqlType") {
            /** @noinspection PhpVoidFunctionResultUsedInspection */
            $this->$name = match ($this->attributeDescription->type) {
                AttributeType::integer16 => SQLType::smallint,
                AttributeType::integer32 => SQLType::int,
                AttributeType::integer64 => SQLType::bigint,
                AttributeType::decimal => SQLType::decimal,
                AttributeType::double => SQLType::double,
                AttributeType::float => SQLType::float,
                AttributeType::string => SQLType::varchar,
                AttributeType::boolean => SQLType::tinyint,
                AttributeType::date => SQLType::timestamp,
                AttributeType::binaryData => SQLType::longblob,
                AttributeType::transformable => SQLType::mediumblob,
                AttributeType::objectID => SQLType::tinyblob,
                AttributeType::uuid => SQLType::uuid,
                AttributeType::uri => SQLType::varbinary,
                AttributeType::undefined => fatal_error("{$this->entity->entityDescription->name}.$this->name cannot use an attribute type of \"Undefined\""),
            };
            return $this->$name;
        } elseif ($name == "triggerKeys") {
            $this->$name = new Set();
            return $this->$name;
        } elseif ($name == "isBackedByTrigger") {
            $this->$name = !$this->triggerKeys->isEmpty;
            return $this->$name;
        } elseif ($name == "isDerivedAttribute") {
            $this->$name = $this->attributeDescription instanceof DerivedAttributeDescription;
            return $this->$name;
        } elseif ($name == "derivationExpression") {
            $this->$name = $this->attributeDescription instanceof DerivedAttributeDescription ? $this->attributeDescription->derivationExpression : null;
            return $this->$name;
        } elseif ($name == "defaultValue") {
            $this->$name = match ($this->sqlType) {
                SQLType::uuid => "UUID()",
                SQLType::timestamp => "CURRENT_TIMESTAMP",
                default => (function (): mixed {
                    $defaultValue = ManagedObject::coercedValue($this->attributeDescription->defaultValue, $this->attributeDescription->type, $this->attributeDescription->attributeValueClassName, $this->attributeDescription->valueTransformerName, $this->attributeDescription->isOptional, true);
                    if (is_string($defaultValue)) {
                        $defaultValue = match ($defaultValue) {
                            "" => $defaultValue,
                            default => "'$defaultValue'"
                        };
                    }
                    return $defaultValue;
                })()
            };
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }

    #[Override]
    public function __set(string $name, mixed $value): void
    {
        if ($name == "attributeDescription" || $name == "triggerKeys" || $name == "isBackedByTrigger" || $name == "isDerivedAttribute" || $name == "derivationExpression") {
            $this->$name = $value;
        } else {
            parent::__set($name, $value);
        }
    }

    public function addKeyForTriggerOnRelationship(SQLRelationship $relationship): void
    {
        $this->triggerKeys->append($relationship->name);
    }
}
