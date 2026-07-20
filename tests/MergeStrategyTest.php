<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\MergeStrategy;
use Sabatier\CoreData\ObjectTrumpStrategy;
use Sabatier\CoreData\OverwriteStrategy;
use Sabatier\CoreData\RollbackStrategy;
use Sabatier\CoreData\StoreTrumpStrategy;
use Sabatier\Foundation\Dictionary;

/**
 * Pins the exact merge semantics of the four MergeStrategy implementations. These classes are
 * one-liners over Dictionary::merging(), but the DIRECTION of the merge is the entire point of
 * conflict resolution: swapping two of them silently corrupts saved data (a store value winning
 * where the in-memory value should have, or vice versa), which is invisible until data is wrong
 * in production. There were no tests holding that direction in place.
 *
 * merging() semantics (verified in Foundation's Dictionary): "$a->merging($b)" clones $a and
 * overwrites its keys with $b's, so keys present in both take $b's value, and keys unique to
 * either side are all preserved.
 *
 * Each fixture deliberately uses three kinds of key so the four strategies are distinguishable
 * from one another (not just "the shared key won"):
 *  - "shared":     present in both snapshots with different values -> reveals who wins a collision
 *  - "objectOnly": present only in the cached (in-memory) snapshot
 *  - "storeOnly":  present only in the persisted (store) snapshot
 */
final class MergeStrategyTest extends TestCase
{
    /** @return Dictionary<mixed> the cached (in-memory) snapshot */
    private static function cached(): Dictionary
    {
        return new Dictionary(["shared" => "object", "objectOnly" => "keepMe"]);
    }

    /** @return Dictionary<mixed> the persisted (store) snapshot */
    private static function persisted(): Dictionary
    {
        return new Dictionary(["shared" => "store", "storeOnly" => "fromStore"]);
    }

    /**
     * Object-trump: in-memory changes win on collision, but store-only keys are still merged in.
     * "persisted->merging(cached)" => base = store, cached overwrites the shared key.
     */
    public function testObjectTrumpKeepsInMemoryValueOnCollisionAndUnionsKeys(): void
    {
        $result = new ObjectTrumpStrategy()->merge(self::cached(), self::persisted());

        $this->assertSame("object", $result["shared"], "in-memory value wins the collision");
        $this->assertSame("keepMe", $result["objectOnly"], "in-memory-only key survives");
        $this->assertSame("fromStore", $result["storeOnly"], "store-only key is still merged in");
    }

    /**
     * Store-trump: store changes win on collision, but object-only keys are still merged in.
     * "cached->merging(persisted)" => base = cached, persisted overwrites the shared key.
     */
    public function testStoreTrumpKeepsStoreValueOnCollisionAndUnionsKeys(): void
    {
        $result = new StoreTrumpStrategy()->merge(self::cached(), self::persisted());

        $this->assertSame("store", $result["shared"], "store value wins the collision");
        $this->assertSame("keepMe", $result["objectOnly"], "in-memory-only key survives");
        $this->assertSame("fromStore", $result["storeOnly"], "store-only key survives");
    }

    /**
     * Overwrite: the entire in-memory snapshot is pushed as-is; the store snapshot is ignored,
     * so a store-only key is dropped. This is what distinguishes it from object-trump.
     */
    public function testOverwriteReturnsTheCachedSnapshotVerbatim(): void
    {
        $result = new OverwriteStrategy()->merge(self::cached(), self::persisted());

        $this->assertSame("object", $result["shared"], "in-memory value is kept");
        $this->assertSame("keepMe", $result["objectOnly"], "in-memory-only key is kept");
        $this->assertNull($result["storeOnly"], "store-only key is dropped (store snapshot ignored)");
    }

    /**
     * Rollback: the entire persisted snapshot is returned; in-memory changes are discarded, so
     * an object-only key is dropped. This is what distinguishes it from store-trump.
     */
    public function testRollbackReturnsThePersistedSnapshotVerbatim(): void
    {
        $result = new RollbackStrategy()->merge(self::cached(), self::persisted());

        $this->assertSame("store", $result["shared"], "store value is kept");
        $this->assertSame("fromStore", $result["storeOnly"], "store-only key is kept");
        $this->assertNull($result["objectOnly"], "in-memory-only key is dropped (in-memory snapshot discarded)");
    }

    /**
     * merging() is non-mutating (it clones), so no strategy may mutate the snapshots handed to
     * it — the same conflict is resolved once per layer, and a mutation would corrupt the second.
     */
    public function testStrategiesDoNotMutateTheirInputs(): void
    {
        $strategies = [
            new ObjectTrumpStrategy(),
            new StoreTrumpStrategy(),
            new OverwriteStrategy(),
            new RollbackStrategy(),
        ];

        foreach ($strategies as $strategy) {
            $this->assertInstanceOf(MergeStrategy::class, $strategy);
            $cached = self::cached();
            $persisted = self::persisted();
            $strategy->merge($cached, $persisted);

            $this->assertSame("object", $cached["shared"], $strategy::class . " must not mutate the cached snapshot");
            $this->assertNull($cached["storeOnly"], $strategy::class . " must not leak store keys into the cached snapshot");
            $this->assertSame("store", $persisted["shared"], $strategy::class . " must not mutate the persisted snapshot");
            $this->assertNull($persisted["objectOnly"], $strategy::class . " must not leak object keys into the persisted snapshot");
        }
    }
}
