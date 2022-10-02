<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 14:32
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLAttribute extends SQLColumn
{
    public function __construct(SQLEntity $entity, public readonly AttributeDescription $attributeDescription)
    {
        parent::__construct($entity, $this->attributeDescription);
    }

    public function __get(string $name)
    {
        if ($name == 'sqlType') {
            $this->$name = match ($this->attributeDescription->type) {
                AttributeType::transformable, AttributeType::objectID, AttributeType::undefined => SQLType::varbinary,
                AttributeType::integer16 => SQLType::smallint,
                AttributeType::integer32 => SQLType::int,
                AttributeType::integer64 => SQLType::bigint,
                AttributeType::decimal => SQLType::decimal,
                AttributeType::double => SQLType::double,
                AttributeType::float => SQLType::float,
                AttributeType::string, AttributeType::uri => SQLType::varchar,
                AttributeType::boolean => SQLType::tinyint,
                AttributeType::date => SQLType::timestamp,
                AttributeType::binaryData => SQLType::blob,
                AttributeType::uuid => SQLType::uuid,
            };
            return $this->$name;
        } else {
            return parent::__get($name);
        }
    }
}
