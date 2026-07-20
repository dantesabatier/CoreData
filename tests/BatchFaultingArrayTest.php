<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\BatchFaultingArray;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;

final class Bead extends ManagedObject
{
}

/**
 * Tests for BatchFaultingArray — the lazy, batched cursor over a fetch result. It counts the
 * total up front, then pages object IDs in fetchBatchSize windows, materializing
 * ManagedObjectID -> ManagedObject on demand as iteration crosses batch boundaries. The batch
 * arithmetic (fetchOffset, cursor, the re-fetch trigger in next()) is classic off-by-one
 * territory and had no coverage; mutation is deliberately disabled.
 *
 * Driven against a real XML-backed stack, which honors fetchOffset/fetchLimit (verified before
 * writing these tests), so the pagination is exercised end to end without a SQL server. Beads
 * carry an integer "n" and are always fetched sorted by n, so iteration order is deterministic.
 */
final class BatchFaultingArrayTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $n = new AttributeDescription();
        $n->name = "n";
        $n->type = AttributeType::integer32;

        $bead = new EntityDescription();
        $bead->name = "Bead";
        $bead->managedObjectClassName = Bead::class;
        $bead->properties = new ArrayClass([$n]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$bead]);
        return $model;
    }

    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-batchfaulting-test-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    /** Seeds $count beads numbered 0..$count-1. */
    private function seed(int $count): void
    {
        $context = $this->context();
        for ($i = 0; $i < $count; $i++) {
            $bead = new Bead($context);
            $bead->n = $i;
        }
        $context->save();
    }

    /**
     * A managed-object BatchFaultingArray over the seeded beads, batched by $batchSize and sorted
     * by n so iteration order is deterministic.
     */
    private function batchArray(int $batchSize, FetchRequestResultType $resultType = FetchRequestResultType::managedObjectResultType): BatchFaultingArray
    {
        /** @var FetchRequest<ManagedObject> $request */
        $request = Bead::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("n", true)]);
        $request->fetchBatchSize = $batchSize;
        $request->resultType = $resultType;
        return new BatchFaultingArray($request, $this->context());
    }

    public function testCountIsTheTotalNotTheBatchSize(): void
    {
        $this->seed(7);
        $batch = $this->batchArray(3);
        $this->assertSame(7, $batch->count, "count reports the full result size, independent of the batch size");
    }

    public function testIterationYieldsEveryRowAcrossBatchBoundaries(): void
    {
        // 7 rows over a batch size of 3 => pages of [0,1,2], [3,4,5], [6]. The final partial page
        // is where off-by-one bugs in the re-fetch trigger would drop or duplicate a row.
        $this->seed(7);
        $seen = [];
        foreach ($this->batchArray(3) as $bead) {
            $seen[] = $bead->n;
        }
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $seen, "every row is visited exactly once, in order, across batches");
    }

    public function testIterationWhenTotalIsAMultipleOfBatchSize(): void
    {
        // 6 rows / batch 3 => two full pages, no partial tail.
        $this->seed(6);
        $seen = [];
        foreach ($this->batchArray(3) as $bead) {
            $seen[] = $bead->n;
        }
        $this->assertSame([0, 1, 2, 3, 4, 5], $seen, "an exact multiple paginates without dropping the boundary row");
    }

    public function testBatchSizeLargerThanTotalIsASinglePage(): void
    {
        $this->seed(2);
        $seen = [];
        foreach ($this->batchArray(10) as $bead) {
            $seen[] = $bead->n;
        }
        $this->assertSame([0, 1], $seen, "a batch larger than the result set returns everything in one page");
    }

    public function testManagedObjectResultTypeMaterializesObjects(): void
    {
        $this->seed(3);
        $visited = 0;
        foreach ($this->batchArray(2, FetchRequestResultType::managedObjectResultType) as $element) {
            $this->assertInstanceOf(ManagedObject::class, $element, "object result type yields materialized ManagedObjects");
            $visited++;
        }
        $this->assertSame(3, $visited, "the in-loop assertions actually ran for every row");
    }

    public function testManagedObjectIDResultTypeYieldsObjectIDs(): void
    {
        $this->seed(3);
        $visited = 0;
        foreach ($this->batchArray(2, FetchRequestResultType::managedObjectIDResultType) as $element) {
            $this->assertInstanceOf(ManagedObjectID::class, $element, "objectID result type yields ManagedObjectIDs, not materialized objects");
            $visited++;
        }
        $this->assertSame(3, $visited, "the in-loop assertions actually ran for every row");
    }

    public function testAppendIsRejected(): void
    {
        $this->seed(1);
        $batch = $this->batchArray(1);
        $this->expectException(InternalInconsistencyException::class);
        $batch->append(new Bead($this->context()));
    }

    public function testOffsetSetIsRejected(): void
    {
        $this->seed(1);
        $batch = $this->batchArray(1);
        $this->expectException(InternalInconsistencyException::class);
        $batch->offsetSet(0, new Bead($this->context()));
    }

    public function testRemoveAtIsRejected(): void
    {
        $this->seed(1);
        $batch = $this->batchArray(1);
        $this->expectException(InternalInconsistencyException::class);
        $batch->removeAt(0);
    }
}
