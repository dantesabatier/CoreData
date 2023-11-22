<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ObjectClass;

/** @internal */
class SQLIndex extends ObjectClass
{
    /** @var ArrayClass<SQLStatement> */
    public readonly ArrayClass $createTableStatements;
    /** @var ArrayClass<SQLStatement> */
    public readonly ArrayClass $dropTableStatements;
    /** @var ArrayClass<SQLStatement> */
    public readonly ArrayClass $updateTableStatements;
    public readonly bool $isUnique;

    public function __construct(public readonly FetchIndexDescription $indexDescription, public readonly SQLEntity $entity)
    {
        $this->isUnique = $this->indexDescription->isUnique();
        $this->createTableStatements = new ArrayClass();
        $this->dropTableStatements = new ArrayClass();
        $this->updateTableStatements = new ArrayClass();
        $this->dropTableStatements->append(new SQLStatement("ALTER TABLE `{$this->entity->tableName}` DROP INDEX IF EXISTS `{$this->indexDescription->name}`"));
        $elements = $this->indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}` {$element->order()}");
        if ($this->isUnique) {
            $this->createTableStatements->append(new SQLStatement("ALTER TABLE `{$this->entity->tableName}` ADD CONSTRAINT `{$this->indexDescription->name}` UNIQUE INDEX IF NOT EXISTS ({$elements->join(", ")}) USING BTREE"));
        } else {
            $this->createTableStatements->append(new SQLStatement("ALTER TABLE `{$this->entity->tableName}` ADD INDEX IF NOT EXISTS `{$this->indexDescription->name}` ({$elements->join(", ")}) USING BTREE"));
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
