<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;
use function Sabatier\Foundation\invalid_mutation;

/**
 * @extends ArrayClass<ManagedObject|ManagedObjectID>
 * @internal
 */
final class BatchFaultingArray extends ArrayClass implements Materializable
{
    private int $length;
    /** @var int<0, max> */
    #[Override]
    public int $count {
        get => $this->length;
    }
    /** @var list<ManagedObject>|list<ManagedObjectID> */
    #[Override]
    public array $array {
        get => iterator_to_array($this);
    }
    private int $fetchLimit;
    private readonly int $startOffset;
    /** @noinspection PhpPropertyOnlyWrittenInspection */
    private int $fetchOffset {
        get => max(($this->cursor - 1) * $this->fetchLimit, 0);
    }
    private int $cursor = 0;
    /** @var ArrayClass<ManagedObject|ManagedObjectID> */
    private ArrayClass $batch;
    /** @var FetchRequest<ManagedObject|ManagedObjectID> */
    private readonly FetchRequest $request;
    private readonly FetchRequestResultType $resultType;
    private readonly ManagedObjectContext $context;

    public function __construct(FetchRequest $fetchRequest, ManagedObjectContext $context)
    {
        parent::__construct();
        // Each batch is an ordinary fetch of the request's own result type: a batch of objects is then hydrated and serialized in one statement, as an unbatched fetch is, rather than faulted in one object at a time.
        /** @var FetchRequest<ManagedObject|ManagedObjectID> $request */
        $request = clone($fetchRequest, [
            "fetchBatchSize" => 0,
        ]);
        $this->request = $request;
        $this->resultType = $fetchRequest->resultType;
        $this->fetchLimit = $fetchRequest->fetchBatchSize;
        $this->startOffset = $fetchRequest->fetchOffset;
        $this->context = $context;
        $this->batch = new ArrayClass();
        $debugDefault = SQLCore::$debugLevel;
        if ($debugDefault->value && ($sqlCore = $context->persistentStoreCoordinator?->persistentStores->first) && $sqlCore instanceof SQLCore && ($statement = new SQLGenerator(new SQLFetchRequestContext($this->request, $context, $sqlCore))->statement)) {
            error_log(sprintf("CoreData: sql: \n%s", $statement->formatted(SQLStatementFormatterStyle::defaultFormatterStyle())));
        }
        // A count is a single row, so the window is applied here and not in the statement: there a limit trims nothing and an offset skips the only row.
        /** @var FetchRequest<Number> $request */
        $request = clone($request, [
            "resultType" => FetchRequestResultType::countResultType,
            "fetchLimit" => 0,
            "fetchOffset" => 0,
        ]);
        SQLCore::$debugLevel = SQLDebugLevel::none;
        /** @noinspection PhpUnhandledExceptionInspection */
        $remaining = max($context->count($request) - $this->startOffset, 0);
        SQLCore::$debugLevel = $debugDefault;
        $this->length = $fetchRequest->fetchLimit ? min($remaining, $fetchRequest->fetchLimit) : $remaining;
    }

    #[Override]
    public function append(mixed $element): void
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

    private function nextBatch(): ArrayClass
    {
        $this->context->reset();
        // The last batch is cut at the end of the window; past it there is nothing to fetch, and a limit of zero would mean no limit at all.
        $batchSize = min($this->fetchLimit, $this->length - $this->fetchOffset);
        if ($batchSize <= 0) {
            $this->cursor += 1;
            return new ArrayClass();
        }
        $request = $this->request;
        $request->fetchOffset = $this->startOffset + $this->fetchOffset;
        $request->fetchLimit = $batchSize;
        $debugDefault = SQLCore::$debugLevel;
        SQLCore::$debugLevel = SQLDebugLevel::none;
        /** @noinspection PhpUnhandledExceptionInspection */
        $result = $this->context->fetch($request);
        SQLCore::$debugLevel = $debugDefault;
        $this->cursor += 1;
        return $result;
    }

    #[Override]
    public function materialize(mixed $element): mixed
    {
        if ($element instanceof ManagedObjectID && $this->resultType === FetchRequestResultType::managedObjectResultType) {
            return $this->context->object($element);
        }
        return $element;
    }

    #[Override]
    public function current(): ManagedObjectID|ManagedObject
    {
        return $this->materialize($this->batch->current());
    }

    #[Override]
    public function next(): void
    {
        parent::next();
        $this->batch->next();
        if ($this->key() === $this->fetchOffset) {
            $this->batch = $this->nextBatch();
        }
    }

    #[Override]
    public function valid(): bool
    {
        return $this->batch->valid();
    }

    #[Override]
    public function rewind(): void
    {
        parent::rewind();
        $this->cursor = 1;
        $this->batch = $this->nextBatch();
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->indices->contains($offset);
    }

    #[Override]
    public function offsetGet(mixed $offset): ManagedObjectID|ManagedObject
    {
        return $this->materialize($this->batch->offsetGet($offset));
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        invalid_mutation();
    }
}
