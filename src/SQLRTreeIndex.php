<?php

namespace Sabatier\CoreData;

use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;

/** @internal */
final class SQLRTreeIndex extends SQLIndex
{
    public function __construct(FetchIndexDescription $indexDescription, SQLEntity $entity)
    {
        parent::__construct($indexDescription, $entity);
        /** @var FetchIndexElementDescription $element */
        $element = $indexDescription->elements->first;
        $property = $element->property;
        if ($property->isOptional) {
            $element->collationType
                |> human_readable_value(...)
                |> (fn($x) => sprintf("Invalid argument for index %s, property \"%s\" cannot be optional", $x, $property->name))
                |> fatal_error(...);
        }
        /** @var SQLEntity $entity */
        $entity = $entity->isRootEntity ? $entity : $entity->rootEntity;
        $this->createTableStatements->append(new SQLStatement("ALTER TABLE `$entity->tableName` ADD CONSTRAINT `$indexDescription->name` SPATIAL INDEX IF NOT EXISTS ({$indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}` $element->order")->join(", ")})"));
    }
}
