<?php

namespace Sabatier\CoreData;

use InvalidArgumentException;
use function Sabatier\Foundation\human_readable_value;

/** @internal */
class SQLRTreeIndex extends SQLIndex
{
    public readonly string $tableName;

    public function __construct(FetchIndexDescription $indexDescription, SQLEntity $entity)
    {
        parent::__construct($indexDescription, $entity);
        $this->tableName = $entity->tableName;
        /** @var FetchIndexElementDescription $element */
        $element = $indexDescription->elements->first();
        $property = $element->property;
        if ($property->isOptional) {
            throw new InvalidArgumentException(sprintf("CoreData: annotation: invalid argument for index %s, property \"%s\" cannot be optional", human_readable_value($element->collationType), $property->name));
        }
        $this->createTableStatements->append(new SQLStatement("ALTER TABLE `$entity->tableName` ADD CONSTRAINT `$indexDescription->name` SPATIAL INDEX IF NOT EXISTS ({$indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}` {$element->order()}")->join(', ')})"));
        $this->updateTableStatements->appendContentsOf($this->createTableStatements);
        $this->updateTableStatements->appendContentsOf($this->dropTableStatements);
    }
}
