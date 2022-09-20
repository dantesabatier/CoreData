<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 07/08/20
 * Time: 12:38
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLSchema
{
    public string $engine = "InnoDB";
    public string $charset = "utf8";
    public string $collation = "utf8_general_ci";

    public function __construct(public readonly string $name, public readonly string $host, public readonly SQLCredential $credential)
    {
    }
}
