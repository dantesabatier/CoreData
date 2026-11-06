<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use function Sabatier\Foundation\fatal_error;

abstract class SQLBatchOperationRequestContext extends SQLStoreRequestContext
{
    /** @var ArrayClass<ManagedObjectID> */
    protected(set) ArrayClass $affectedObjectIDs {
        get => $this->affectedObjectIDs ??= new ArrayClass();
    }

    /** @param array<string, mixed> $row */
    protected function objectIDForRow(array $row, SQLEntity $entity): ManagedObjectID
    {
        /** @var string $entityName */
        $entityName = $row[$entity->entityKey->columnName] ?? fatal_error("invalid row: {$entity->entityKey->columnName} cannot be null");
        /** @var SQLEntity $rowEntity */
        $rowEntity = $this->sqlCore->model->entitiesByName[$entityName] ?? fatal_error("invalid row: entity \"$entityName\" does not exist");
        return $this->sqlCore->objectID($rowEntity->entityDescription, $row[$rowEntity->primaryKey->columnName]);
    }
}
