<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ObjectClass;

/** @internal */
class SQLIndex extends ObjectClass
{
    /** @var ArrayClass<SQLStatement> */
    public ArrayClass $createTableStatements;
    /** @var ArrayClass<SQLStatement> */
    public ArrayClass $dropTableStatements;
    /** @var ArrayClass<SQLStatement> */
    public ArrayClass $updateTableStatements;
    public readonly bool $isUnique;

    public function __construct(public readonly FetchIndexDescription $indexDescription, public readonly SQLEntity $entity)
    {
        $entity = $this->entity;
        $indexDescription = $this->indexDescription;
        $this->isUnique = $indexDescription->isUnique();
        $this->createTableStatements = new ArrayClass();
        $this->dropTableStatements = new ArrayClass();
        $this->updateTableStatements = new ArrayClass();
        $this->dropTableStatements->append(new SQLStatement("ALTER TABLE `$entity->tableName` DROP INDEX IF EXISTS `$indexDescription->name`"));
        if ($this->isUnique) {
            $this->createTableStatements->append(new SQLStatement("ALTER TABLE `$entity->tableName` ADD CONSTRAINT `$indexDescription->name` UNIQUE INDEX IF NOT EXISTS ({$indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}` {$element->order()}")->join(', ')}) USING BTREE"));
        } else {
            $this->createTableStatements->append(new SQLStatement("ALTER TABLE `$entity->tableName` ADD INDEX IF NOT EXISTS `$indexDescription->name` ({$indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}` {$element->order()}")->join(', ')}) USING BTREE"));
        }
        $this->updateTableStatements->appendContentsOf($this->createTableStatements);
        $this->updateTableStatements->appendContentsOf($this->dropTableStatements);
    }

    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLIndex) {
            return $this->indexDescription->isEqual($other->indexDescription);
        }
        return false;
    }
}
