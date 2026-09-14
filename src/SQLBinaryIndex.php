<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

/** @internal */
final class SQLBinaryIndex extends SQLIndex
{
    /** @var ArrayClass<SQLStatement> A hash index carries no sort order for its columns, which is why FetchIndexElementDescription::$order is empty for a binary collation. */
    protected(set) ArrayClass $createTableStatements {
        get => $this->createTableStatements ??= new ArrayClass([new SQLStatement("ALTER TABLE `{$this->entity->tableName}` ADD INDEX IF NOT EXISTS `{$this->indexDescription->name}` ({$this->indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}`")->join(", ")}) USING HASH")]);
    }
}
