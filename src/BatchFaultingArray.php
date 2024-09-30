<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Range;
use function Sabatier\Foundation\invalid_mutation;

/**
 * @extends ArrayClass<ManagedObject|ManagedObjectID>
 * @internal
 */
class BatchFaultingArray extends ArrayClass
{
    private readonly int $count;
    private readonly int $fetchLimit;
    private int $cursor = 0;
    /** @var ArrayClass<ManagedObjectID> */
    private ArrayClass $objectIDs;
    /** FetchRequest<ManagedObjectID> */
    private readonly FetchRequest $request;
    private readonly FetchRequestResultType $resultType;
    private readonly ManagedObjectContext $context;
    private readonly Range $indices;

    public function __construct(FetchRequest $fetchRequest, ManagedObjectContext $context)
    {
        parent::__construct();
        $this->request = clone $fetchRequest;
        $this->request->fetchBatchSize = 0;
        $this->request->resultType = FetchRequestResultType::managedObjectIDResultType;
        $this->resultType = $fetchRequest->resultType;
        $this->fetchLimit = $fetchRequest->fetchBatchSize;
        $this->context = $context;
        $this->objectIDs = new ArrayClass();
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->count = $context->count($this->request);
        $this->indices = new Range(0, $this->count);
    }

    #[Override]
    public function indices(): Range
    {
        return $this->indices;
    }

    #[Override]
    public function append(mixed $element): void
    {
        invalid_mutation();
    }

    #[Override]
    public function insert(mixed $newElement): never
    {
        invalid_mutation();
    }

    #[Override]
    public function insertAt(mixed $element, int $at): void
    {
        invalid_mutation();
    }

    #[Override]
    public function removeAt(int $index): never
    {
        invalid_mutation();
    }

    #[Override]
    public function setArray(ArrayClass $array): void
    {
        invalid_mutation();
    }

    private function fetchOffset(): int
    {
        return max(($this->cursor - 1) * $this->fetchLimit, 0);
    }

    private function arrayFromObjectIDs(): ArrayClass
    {
        $this->context->reset();
        $request = $this->request;
        $request->fetchOffset = $this->fetchOffset();
        $request->fetchLimit = $this->fetchLimit;
        /** @noinspection PhpUnhandledExceptionInspection */
        $result = $this->context->fetch($request);
        $this->cursor += 1;
        return $result;
    }

    #[Override]
    public function current(): ManagedObjectID|ManagedObject
    {
        $objectID = $this->objectIDs->current();
        if ($this->resultType === FetchRequestResultType::managedObjectIDResultType) {
            return $objectID;
        }
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->context->object($objectID);
    }

    #[Override]
    public function next(): void
    {
        parent::next();
        $this->objectIDs->next();
        if ($this->key() === $this->fetchOffset()) {
            $this->objectIDs = $this->arrayFromObjectIDs();
        }
    }

    #[Override]
    public function rewind(): void
    {
        parent::rewind();
        $this->cursor = 1;
        $this->objectIDs = $this->arrayFromObjectIDs();
    }

    #[Override]
    public function count(): int
    {
        return $this->count;
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->indices->contains($offset);
    }

    #[Override]
    public function offsetGet(mixed $offset): ManagedObjectID|ManagedObject
    {
        $objectID = $this->objectIDs->offsetGet($offset);
        if ($this->resultType === FetchRequestResultType::managedObjectIDResultType) {
            return $objectID;
        }
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->context->object($objectID);
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        invalid_mutation();
    }
}
