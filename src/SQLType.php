<?php

namespace Sabatier\CoreData;

/** @internal */
enum SQLType: string
{
    case tinyint = 'TINYINT';
    case smallint = 'SMALLINT';
    case mediumint = 'MEDIUMINT';
    case int = 'INT';
    case bigint = 'BIGINT';
    case decimal = 'DECIMAL';
    case float = 'FLOAT';
    case double = 'DOUBLE';
    case bit = 'BIT';
    case char = 'CHAR';
    case varchar = 'VARCHAR';
    case varbinary = 'VARBINARY';
    case blob = 'BLOB';
    case timestamp = 'TIMESTAMP';
    case uuid = 'UUID';
    case unknown = 'UNKNOWN';
}
