<?php

namespace Sabatier\CoreData;

/** @internal */
final class SQLBinaryIndex extends SQLIndex
{
    public function __construct(FetchIndexDescription $indexDescription, SQLEntity $entity)
    {
        parent::__construct($indexDescription, $entity);
        /** @var SQLEntity $entity */
        $entity = $entity->isRootEntity ? $entity : $entity->rootEntity;
        $this->createTableStatements[] = new SQLStatement("ALTER TABLE `$entity->tableName` ADD CONSTRAINT `$indexDescription->name` INDEX IF NOT EXISTS ({$indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}`")->join(", ")}) USING HASH");
    }
}
