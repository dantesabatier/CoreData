<?php

namespace Sabatier\CoreData;

/** @internal */
enum SQLType: string
{
    case tinyint = "TINYINT";
    case smallint = "SMALLINT";
    case mediumint = "MEDIUMINT";
    case int = "INT";
    case bigint = "BIGINT";
    case decimal = "DECIMAL";
    case float = "FLOAT";
    case double = "DOUBLE";
    case binary = "BINARY";
    case tinyblob = "TINYBLOB";
    case blob = "BLOB";
    case mediumblob = "MEDIUMBLOB";
    case longblob = "LONGBLOB";
    case bit = "BIT";
    case text = "TEXT";
    case char = "CHAR";
    case varchar = "VARCHAR";
    case varbinary = "VARBINARY";
    case timestamp = "TIMESTAMP";
    case uuid = "UUID";
    case unknown = "UNKNOWN";
}
