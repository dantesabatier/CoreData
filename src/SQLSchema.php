<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 07/08/20
 * Time: 12:38
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\ProcessInfo;

/** @internal */
class SQLSchema
{
    public string $engine = "InnoDB";
    public string $charset = "utf8";
    public string $collation = "utf8_general_ci";

    public function __construct(public readonly string $name, public readonly string $host, public readonly SQLCredential $credential)
    {
    }

    public static function schema(?string $name = null): SQLSchema
    {
        return new SQLSchema($name ?? ProcessInfo::processInfo()->environment["SQLSchemaName"] ?? throw new InternalInconsistencyException("Schema must have a name"), ProcessInfo::processInfo()->environment["SQLSchemaHost"] ?? "localhost", new SQLCredential(ProcessInfo::processInfo()->environment["SQLSchemaCredentialUser"] ?? "root", ProcessInfo::processInfo()->environment["SQLSchemaCredentialPassword"]));
    }
}
