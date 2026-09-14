<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;

/** @internal */
final class SQLRTreeIndex extends SQLIndex
{
    /** @var ArrayClass<SQLStatement> ADD SPATIAL INDEX takes neither a CONSTRAINT symbol nor an index_type, and rejects a sort order on its columns outright (MariaDB 1064) — the keyword already states the index kind. */
    protected(set) ArrayClass $createTableStatements {
        get => $this->createTableStatements ??= new ArrayClass([new SQLStatement("ALTER TABLE `{$this->entity->tableName}` ADD SPATIAL INDEX IF NOT EXISTS `{$this->indexDescription->name}` ({$this->indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}`")->join(", ")})")]);
    }

    public function __construct(FetchIndexDescription $indexDescription, SQLEntity $entity)
    {
        parent::__construct($indexDescription, $entity);
        /** @var FetchIndexElementDescription $element */
        $element = $indexDescription->elements->first;
        $property = $element->property;
        !$property->isOptional ?: $element->collationType
                |> human_readable_value(...)
                |> (fn(string $x): string => sprintf("Invalid argument for index %s, property \"%s\" cannot be optional", $x, $property->name))
                |> fatal_error(...);
    }
}
