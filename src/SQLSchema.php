<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 07/08/20
 * Time: 12:38
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ProcessInfo;
use function Sabatier\Foundation\fatal_error;

/** @internal */
class SQLSchema
{
    public string $engine = "InnoDB";
    public string $charset = "utf8";
    public string $collation = "utf8_general_ci";
    public string $socket = "/tmp/mysql.sock";

    public function __construct(public readonly string $name, public readonly string $host, public readonly SQLCredential $credential)
    {
    }

    public static function schema(?string $name = null): SQLSchema
    {
        return new SQLSchema($name ?? ProcessInfo::processInfo()->environment["SQL_SCHEMA_NAME"] ?? fatal_error("Environment variable \"SQL_SCHEMA_NAME\" cannot be null"), ProcessInfo::processInfo()->environment["SQL_SCHEMA_HOST"] ?? "localhost", new SQLCredential(ProcessInfo::processInfo()->environment["SQL_SCHEMA_CREDENTIAL_USER"] ?? "root", ProcessInfo::processInfo()->environment["SQL_SCHEMA_CREDENTIAL_PASSWORD"]));
    }
}
