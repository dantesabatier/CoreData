<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\MergeConflict;
use Sabatier\CoreData\MergePolicy;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;

/**
 * Tests the orchestration in MergePolicy: which MergeStrategy each policy type selects, and the
 * control flow of tryResolveConflicts() (empty list is a no-op success; the error policy refuses
 * to merge and raises). The strategy chosen is private, so it is verified by BEHAVIOR — the
 * snapshot MergePolicy pushes onto the conflicting object is exactly what the corresponding
 * strategy would produce.
 *
 * MergeStrategyTest already pins each strategy's merge() in isolation; here the object under test
 * is the mapping (MergePolicyType -> strategy) plus the resolve loop. The source object is a
 * partial ManagedObject mock (constructor disabled) that captures the resolved snapshot handed to
 * updateFromSnapshot(), keeping the test independent of the real hydration/faulting machinery.
 */
final class MergePolicyTest extends TestCase
{
    /** @return Dictionary<mixed> */
    private static function cached(): Dictionary
    {
        return new Dictionary(["shared" => "object", "objectOnly" => "keepMe"]);
    }

    /** @return Dictionary<mixed> */
    private static function persisted(): Dictionary
    {
        return new Dictionary(["shared" => "store", "storeOnly" => "fromStore"]);
    }

    /**
     * Builds a MergeConflict whose source object is a mock capturing the snapshot that resolution
     * applies. The captured Dictionary is written into $captured by reference.
     *
     * @param Dictionary<mixed>|null $captured
     */
    private function conflictCapturing(?Dictionary &$captured): MergeConflict
    {
        // A test stub (not a mock) is the right tool here: resolution's effect is captured via
        // the callback below, so there are no call expectations to verify. Using createStub also
        // avoids PHPUnit's "no expectations configured for mock" notice, which this project's
        // configuration escalates to a failure.
        $object = $this->createStub(ManagedObject::class);
        $object->method("updateFromSnapshot")->willReturnCallback(
            function (Dictionary $snapshot) use (&$captured): void {
                $captured = $snapshot;
            },
        );
        return new MergeConflict($object, 2, 1, self::cached(), self::persisted());
    }

    public function testObjectTrumpPolicyAppliesTheObjectTrumpMerge(): void
    {
        $captured = null;
        MergePolicy::mergeByPropertyObjectTrump()->resolveConflicts(new ArrayClass([$this->conflictCapturing($captured)]));

        $this->assertNotNull($captured, "the policy resolved the conflict and pushed a snapshot");
        $this->assertSame("object", $captured["shared"], "object-trump keeps the in-memory value on collision");
        $this->assertSame("fromStore", $captured["storeOnly"], "object-trump still unions the store-only key");
    }

    public function testStoreTrumpPolicyAppliesTheStoreTrumpMerge(): void
    {
        $captured = null;
        MergePolicy::mergeByPropertyStoreTrump()->resolveConflicts(new ArrayClass([$this->conflictCapturing($captured)]));

        $this->assertNotNull($captured);
        $this->assertSame("store", $captured["shared"], "store-trump keeps the store value on collision");
        $this->assertSame("keepMe", $captured["objectOnly"], "store-trump still unions the object-only key");
    }

    public function testOverwritePolicyPushesTheCachedSnapshot(): void
    {
        $captured = null;
        MergePolicy::overwrite()->resolveConflicts(new ArrayClass([$this->conflictCapturing($captured)]));

        $this->assertNotNull($captured);
        $this->assertSame("object", $captured["shared"]);
        $this->assertNull($captured["storeOnly"], "overwrite ignores the store snapshot entirely");
    }

    public function testRollbackPolicyPushesThePersistedSnapshot(): void
    {
        $captured = null;
        MergePolicy::rollback()->resolveConflicts(new ArrayClass([$this->conflictCapturing($captured)]));

        $this->assertNotNull($captured);
        $this->assertSame("store", $captured["shared"]);
        $this->assertNull($captured["objectOnly"], "rollback discards the in-memory snapshot entirely");
    }

    public function testErrorPolicyRaisesOnANonEmptyConflictList(): void
    {
        $captured = null;
        $this->expectException(InternalInconsistencyException::class);
        MergePolicy::error()->resolveConflicts(new ArrayClass([$this->conflictCapturing($captured)]));
    }

    public function testErrorPolicyDoesNotResolveTheConflict(): void
    {
        $captured = null;
        try {
            MergePolicy::error()->resolveConflicts(new ArrayClass([$this->conflictCapturing($captured)]));
        } catch (InternalInconsistencyException) {
            // expected
        }
        $this->assertNull($captured, "the error policy must not apply any merge before raising");
    }

    public function testAnEmptyConflictListIsANoOpEvenForTheErrorPolicy(): void
    {
        // tryResolveConflicts short-circuits to success on an empty list, so even the error
        // policy — which otherwise always raises — must not throw when there is nothing to merge.
        MergePolicy::error()->resolveConflicts(new ArrayClass());
        MergePolicy::mergeByPropertyObjectTrump()->resolveConflicts(new ArrayClass());
        $this->addToAssertionCount(1);
    }
}
