<?php

namespace Sabatier\CoreData;

/** @internal */
class SQLBinaryIndex extends SQLIndex
{
    public function __construct(FetchIndexDescription $indexDescription, SQLEntity $entity)
    {
        parent::__construct($indexDescription, $entity);
        /** @var SQLEntity $entity */
        $entity = $entity->isRootEntity ? $entity : $entity->rootEntity;
        $this->createTableStatements->append(new SQLStatement("ALTER TABLE `$entity->tableName` ADD CONSTRAINT `$indexDescription->name` INDEX IF NOT EXISTS ({$indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}`")->join(', ')}) USING HASH"));
        $this->updateTableStatements->appendContentsOf($this->createTableStatements);
        $this->updateTableStatements->appendContentsOf($this->dropTableStatements);
    }
}
