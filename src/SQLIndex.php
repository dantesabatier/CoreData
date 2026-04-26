<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ObjectClass;

/** @internal */
class SQLIndex extends ObjectClass
{
    /** @var ArrayClass<SQLStatement> */
    protected(set) ArrayClass $createTableStatements {
        get {
            if (isset($this->createTableStatements)) {
                return $this->createTableStatements;
            }
            $createTableStatements = new ArrayClass();
            $elements = $this->indexDescription->elements->map(fn(FetchIndexElementDescription $element): string => "`{$element->property->name}` $element->order");
            if ($this->isUnique) {
                $createTableStatements->append(new SQLStatement("ALTER TABLE `{$this->entity->tableName}` ADD CONSTRAINT `{$this->indexDescription->name}` UNIQUE INDEX IF NOT EXISTS ({$elements->join(", ")}) USING BTREE"));
            } else {
                $createTableStatements->append(new SQLStatement("ALTER TABLE `{$this->entity->tableName}` ADD INDEX IF NOT EXISTS `{$this->indexDescription->name}` ({$elements->join(", ")}) USING BTREE"));
            }
            return $this->createTableStatements = $createTableStatements;
        }
    }
    /** @var ArrayClass<SQLStatement> */
    protected(set) ArrayClass $dropTableStatements {
        get => $this->dropTableStatements ??= new ArrayClass([new SQLStatement("ALTER TABLE `{$this->entity->tableName}` DROP INDEX IF EXISTS `{$this->indexDescription->name}`")]);
    }
    /** @var ArrayClass<SQLStatement> */
    protected(set) ArrayClass $updateTableStatements {
        get => $this->updateTableStatements ??= new ArrayClass([...$this->createTableStatements->array, ...$this->updateTableStatements->array]);
    }
    public bool $isUnique {
        get => $this->indexDescription->isUnique;
    }

    public function __construct(public readonly FetchIndexDescription $indexDescription, public readonly SQLEntity $entity)
    {
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof SQLIndex) {
            return $this->indexDescription->isEqual($other->indexDescription);
        }
        return false;
    }
}
