<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 14:32
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SQLAttribute extends SQLColumn
{
    public AttributeDescription $attributeDescription {
        get {
            /** @var AttributeDescription $attributeDescription */
            $attributeDescription = $this->propertyDescription;
            return $attributeDescription;
        }
    }
    /** @var Set<string> */
    private(set) Set $triggerKeys {
        get => $this->triggerKeys ??= new Set();
    }
    public bool $isBackedByTrigger {
        get => !$this->triggerKeys->isEmpty;
    }
    public bool $isDerivedAttribute {
        get => $this->attributeDescription instanceof DerivedAttributeDescription;
    }
    public ?Expression $derivationExpression {
        get => $this->attributeDescription instanceof DerivedAttributeDescription ? $this->attributeDescription->derivationExpression : null;
    }
    public bool $isDeterministic {
        get => $this->attributeDescription instanceof DerivedAttributeDescription && $this->attributeDescription->isDeterministic;
    }
    public bool $usesKeyValueCoding {
        get => $this->attributeDescription instanceof DerivedAttributeDescription && $this->attributeDescription->usesKeyValueCoding;
    }
    public bool $usesKeyValueOperator {
        get => $this->attributeDescription instanceof DerivedAttributeDescription && $this->attributeDescription->usesKeyValueOperator;
    }
    public bool $isRuntimeOnly {
        get => $this->attributeDescription instanceof DerivedAttributeDescription && $this->attributeDescription->isRuntimeOnly;
    }
    public bool $isCompositeAttribute {
        get => $this->attributeDescription instanceof CompositeAttributeDescription;
    }
    private mixed $coercedDefaultValue {
        get {
            if (!isset($this->coercedDefaultValue)) {
                $coercedValue = ManagedObject::coercedValue($this->attributeDescription->defaultValue, $this->attributeDescription->type, $this->attributeDescription->attributeValueClassName, $this->attributeDescription->valueTransformerName, $this->attributeDescription->isOptional, true);
                if (is_string($coercedValue)) {
                    $coercedValue = match ($coercedValue) {
                        "" => $coercedValue,
                        default => "'$coercedValue'"
                    };
                }
                $this->coercedDefaultValue = $coercedValue;
            }
            return $this->coercedDefaultValue;
        }
    }
    public mixed $defaultValue {
        get => match ($this->sqlType) {
            SQLType::uuid => "UUID()",
            SQLType::timestamp => "CURRENT_TIMESTAMP",
            default => $this->coercedDefaultValue
        };
    }
    public SQLType $sqlType {
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        get => $this->sqlType ??= match ($this->attributeDescription->type) {
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
            AttributeType::compositeAttributeType => SQLType::text,
            AttributeType::undefined => fatal_error("{$this->entity->entityDescription->name}.$this->name cannot use an attribute type of \"Undefined\""),
        };
    }

    public function __construct(SQLEntity $entity, AttributeDescription $attributeDescription)
    {
        parent::__construct($entity, $attributeDescription);
    }

    public function addKeyForTriggerOnRelationship(SQLRelationship $relationship): void
    {
        $this->triggerKeys->insert($relationship->name);
    }
}
