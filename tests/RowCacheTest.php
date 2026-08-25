<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\APCuRowCache;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DefaultRowCache;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\NullRowCache;
use Sabatier\CoreData\PersistentStoreCache;
use Sabatier\CoreData\PropertyDescription;
use Sabatier\CoreData\QueryGenerationToken;
use Sabatier\CoreData\RowCache;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;

/**
 * Contract tests for the RowCache backends.
 *
 * RowCache is the store's L2 cache: it holds raw snapshots keyed by ManagedObjectID so a
 * fetch can skip the database. Every backend (in-process, APCu, Redis, Memcached, and the
 * no-op) implements the same PersistentStoreCache interface, so the behavior is asserted once
 * here and run against each backend that this machine can actually reach.
 *
 * Two things make a shared suite the right shape. The interface is the contract callers rely
 * on, so a backend that stores a snapshot but reports hasSnapshot() === false is broken in a
 * way only a cross-backend comparison reveals. And NullRowCache is a deliberate counter-example
 * — it must accept every call and remember nothing — so it is exercised separately rather than
 * being held to the storing contract.
 *
 * Backends needing a server (Redis, Memcached) are skipped when nothing is listening, so the
 * suite stays runnable on a bare checkout; APCu is skipped unless it is enabled for CLI.
 */
final class RowCacheTest extends TestCase
{
    /**
     * Each caching backend, as a name => factory pair. A factory that cannot run on this
     * machine returns null and the test is skipped rather than failed.
     *
     * @return array<string, array{callable(): ?RowCache}>
     */
    public static function cachingBackends(): array
    {
        return [
            "DefaultRowCache" => [static fn(): RowCache => new DefaultRowCache()],
            "APCuRowCache" => [static fn(): ?RowCache =>
                extension_loaded("apcu") && filter_var(ini_get("apc.enable_cli"), FILTER_VALIDATE_BOOLEAN)
                    ? new APCuRowCache()
                    : null,
            ],
        ];
    }

    /**
     * Builds a finalized one-entity model, the way the coordinator sees it, and returns the
     * entity so object IDs can be constructed against it.
     */
    private static function entity(string $name = "Person"): EntityDescription
    {
        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = $name;
        $entity->properties = new ArrayClass([$label]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $entity;
    }

    private static function objectID(string $reference, string $entityName = "Person"): ManagedObjectID
    {
        return new ManagedObjectID(self::entity($entityName), $reference);
    }

    /** @return Dictionary<mixed> */
    private static function snapshot(string $label): Dictionary
    {
        return new Dictionary(["label" => $label]);
    }

    /**
     * @param callable(): ?RowCache $factory
     */
    private function backend(callable $factory): RowCache
    {
        $cache = $factory();
        if (!$cache instanceof RowCache) {
            $this->markTestSkipped("this caching backend is not available on this machine");
        }
        return $cache;
    }

    /**
     * The core round-trip: what goes in comes back out, and hasSnapshot() agrees with it.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testSnapshotRoundTrips(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("round-trip");

        $this->assertFalse($cache->hasSnapshot($objectID), "nothing is cached before the first write");
        $this->assertNull($cache->snapshot($objectID), "an absent snapshot reads back as null, not an empty Dictionary");

        $cache->setSnapshot(self::snapshot("stored"), $objectID);

        $this->assertTrue($cache->hasSnapshot($objectID), "hasSnapshot must agree with what was just written");
        $this->assertSame("stored", $cache->snapshot($objectID)?->offsetGet("label"));
    }

    /**
     * Snapshots are keyed per object: writing one must not disturb another.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testSnapshotsAreKeyedPerObject(callable $factory): void
    {
        $cache = $this->backend($factory);
        $first = self::objectID("first");
        $second = self::objectID("second");

        $cache->setSnapshot(self::snapshot("one"), $first);
        $cache->setSnapshot(self::snapshot("two"), $second);

        $this->assertSame("one", $cache->snapshot($first)?->offsetGet("label"));
        $this->assertSame("two", $cache->snapshot($second)?->offsetGet("label"));
    }

    /**
     * Two objects with the same reference in different entities are different cache entries —
     * the entity name is part of the key, via the object ID's URI.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testEntityNameParticipatesInTheKey(callable $factory): void
    {
        $cache = $this->backend($factory);
        $person = self::objectID("shared-reference", "Person");
        $company = self::objectID("shared-reference", "Company");

        $cache->setSnapshot(self::snapshot("person"), $person);
        $cache->setSnapshot(self::snapshot("company"), $company);

        $this->assertSame("person", $cache->snapshot($person)?->offsetGet("label"), "the same reference in another entity must not collide");
        $this->assertSame("company", $cache->snapshot($company)?->offsetGet("label"));
    }

    /**
     * A property-scoped snapshot (a cached relationship) is a separate entry from the object's
     * own snapshot, even for the same object ID.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testPropertyScopedSnapshotIsSeparateFromTheObjectSnapshot(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("scoped");
        $property = self::propertyNamed("label");

        $cache->setSnapshot(self::snapshot("object"), $objectID);
        $cache->setSnapshot(self::snapshot("property"), $objectID, 3600, $property);

        $this->assertSame("object", $cache->snapshot($objectID)?->offsetGet("label"));
        $this->assertSame("property", $cache->snapshot($objectID, $property)?->offsetGet("label"));
    }

    /**
     * Deleting is scoped the same way: dropping the object's snapshot leaves a property-scoped
     * one in place, since they are distinct keys.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testDeleteSnapshotOnlyRemovesTheScopeAskedFor(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("scoped-delete");
        $property = self::propertyNamed("label");

        $cache->setSnapshot(self::snapshot("object"), $objectID);
        $cache->setSnapshot(self::snapshot("property"), $objectID, 3600, $property);

        $cache->deleteSnapshot($objectID);

        $this->assertFalse($cache->hasSnapshot($objectID), "the object's own snapshot is gone");
        $this->assertTrue($cache->hasSnapshot($objectID, $property), "the property-scoped snapshot survives");
    }

    /**
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testDeleteSnapshotOfAbsentEntryIsHarmless(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("never-written");

        $cache->deleteSnapshot($objectID);

        $this->assertFalse($cache->hasSnapshot($objectID), "deleting what was never cached is a no-op, not an error");
    }

    /**
     * deleteSnapshots() drops every listed object, leaving the unlisted ones alone.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testDeleteSnapshotsRemovesOnlyTheListedObjects(callable $factory): void
    {
        $cache = $this->backend($factory);
        $doomed = self::objectID("doomed");
        $spared = self::objectID("spared");

        $cache->setSnapshot(self::snapshot("doomed"), $doomed);
        $cache->setSnapshot(self::snapshot("spared"), $spared);

        $cache->deleteSnapshots(new ArrayClass([$doomed]));

        $this->assertFalse($cache->hasSnapshot($doomed));
        $this->assertTrue($cache->hasSnapshot($spared), "an object absent from the list keeps its snapshot");
    }

    /**
     * The generation counter starts at 1 and advances monotonically, per store.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testGenerationStartsAtOneAndAdvances(callable $factory): void
    {
        $cache = $this->backend($factory);
        $store = "store-" . __FUNCTION__;

        $this->assertSame(1, $cache->currentGenerationForStore($store), "an uninitialized store reports generation 1");

        $advanced = $cache->advanceGenerationForStore($store);

        $this->assertSame(2, $advanced, "advancing returns the new value");
        $this->assertSame(2, $cache->currentGenerationForStore($store), "and the new value is what is read back");
    }

    /**
     * Generations are namespaced per store: advancing one must not move another.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testGenerationsAreIndependentPerStore(callable $factory): void
    {
        $cache = $this->backend($factory);
        $first = "store-a-" . __FUNCTION__;
        $second = "store-b-" . __FUNCTION__;

        // Read both first, the way the store does before it ever advances, so the comparison is about namespacing and not about how a cold counter initializes.
        $firstBefore = $cache->currentGenerationForStore($first);
        $secondBefore = $cache->currentGenerationForStore($second);

        $cache->advanceGenerationForStore($first);

        $this->assertGreaterThan($firstBefore, $cache->currentGenerationForStore($first), "the advanced store moved forward");
        $this->assertSame($secondBefore, $cache->currentGenerationForStore($second), "the second store's generation is untouched");
    }

    /**
     * The property the store actually depends on, asserted in the sequence the store actually
     * performs: SQLCore reads `currentGeneration` (lazily, on first use) and later advances it
     * after a write. The generation is part of the query cache key
     * (`/query/{origin}/{generation}/{hash}`), so if an advance failed to move past the value a
     * reader had already observed, a fetch issued after a save could still be served the
     * pre-save result.
     *
     * Asserted as a relation rather than fixed numbers because the backends differ in how they
     * initialize a cold counter (see testAdvanceWithoutAPriorReadDiffersAcrossBackends); what
     * every backend must guarantee is that read-then-advance strictly increases.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testReadThenAdvanceAlwaysMovesTheGenerationForward(callable $factory): void
    {
        $cache = $this->backend($factory);
        $store = "store-" . __FUNCTION__ . "-" . bin2hex(random_bytes(4));

        $observed = $cache->currentGenerationForStore($store);
        $advanced = $cache->advanceGenerationForStore($store);

        $this->assertGreaterThan($observed, $advanced, "an advance must move past the generation a reader already saw");
        $this->assertSame($advanced, $cache->currentGenerationForStore($store), "advance's return value must be what is read back");

        $again = $cache->advanceGenerationForStore($store);
        $this->assertGreaterThan($advanced, $again, "a second advance must move past the first");
    }

    /**
     * Records a harmless difference in how a cold counter is initialized, so that a future
     * change to it is a deliberate one.
     *
     * Advancing a generation that no reader has touched yet returns 2 from `DefaultRowCache`
     * (it reads the implicit 1 and adds one) but 1 from `APCuRowCache`, because `apcu_inc()` on
     * a missing key creates it at 1 and reports success. Redis `INCR` behaves like APCu, and
     * Memcached's `increment()` fails on a missing key so its code falls back to `add($key, 1)`
     * and also returns 1.
     *
     * This does NOT affect the store. SQLCore always reads `currentGeneration` before it
     * advances — and every backend's read initializes the counter — so the live path is the
     * read-then-advance sequence pinned above, which increases correctly on all of them. The
     * bare-advance case tested here is not one the framework performs.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testAdvanceWithoutAPriorReadDiffersAcrossBackends(callable $factory): void
    {
        $cache = $this->backend($factory);
        $store = "store-" . __FUNCTION__ . "-" . bin2hex(random_bytes(4));

        $advanced = $cache->advanceGenerationForStore($store);

        $expected = $cache instanceof DefaultRowCache ? 2 : 1;
        $this->assertSame($expected, $advanced, "cold-start initialization differs by backend; recorded, and harmless because the store reads first");
        $this->assertSame($advanced, $cache->currentGenerationForStore($store), "whatever it returns must be what is stored");
    }

    // --- Query keys ---

    /**
     * The query key must be deterministic — two equivalent requests have to land on the same
     * cache entry, or the cache never hits.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testQueryKeyIsStableForAnEquivalentRequest(callable $factory): void
    {
        $cache = $this->backend($factory);
        $token = new QueryGenerationToken("store", 1, 1);

        $first = $cache->queryKeyForRequest(self::fetchRequest(50), $token);
        $second = $cache->queryKeyForRequest(self::fetchRequest(50), $token);

        $this->assertSame($first, $second, "two identically-configured requests must produce the same key");
    }

    /**
     * ...and it must separate requests that differ, otherwise one query's cached result would be
     * served for another. The batch size is part of the canonical description, so it changes the
     * key.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testQueryKeyDistinguishesDifferentRequests(callable $factory): void
    {
        $cache = $this->backend($factory);
        $token = new QueryGenerationToken("store", 1, 1);

        $this->assertNotSame(
            $cache->queryKeyForRequest(self::fetchRequest(50), $token),
            $cache->queryKeyForRequest(self::fetchRequest(100), $token),
            "requests differing in their canonical description must not share a key",
        );
    }

    /**
     * The generation is what invalidates cached queries after a write, so it has to change the
     * key even for an identical request.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testQueryKeyChangesWithTheGeneration(callable $factory): void
    {
        $cache = $this->backend($factory);
        $request = self::fetchRequest(50);

        $this->assertNotSame(
            $cache->queryKeyForRequest($request, new QueryGenerationToken("store", 1, 1)),
            $cache->queryKeyForRequest($request, new QueryGenerationToken("store", 1, 2)),
            "advancing the generation must invalidate the previous key",
        );
    }

    /**
     * The canonical description is digested, not escaped, so the key stays short and
     * backend-safe no matter how large the request is — Memcached rejects keys over 250 bytes,
     * and the old urlencode approach grew the key beyond the length of its input.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testQueryKeyLengthDoesNotGrowWithTheRequest(callable $factory): void
    {
        $cache = $this->backend($factory);
        $token = new QueryGenerationToken("store", 1, 1);

        $small = self::fetchRequest(50);
        $large = self::fetchRequest(50);
        $large->predicate = Predicate::format("label BEGINSWITH \"a very long needle repeated to make the canonical description substantially longer than the digest\"");

        $this->assertSame(
            strlen($cache->queryKeyForRequest($small, $token)),
            strlen($cache->queryKeyForRequest($large, $token)),
            "a bigger request must not produce a longer key",
        );
        $this->assertLessThan(250, strlen($cache->queryKeyForRequest($large, $token)), "the key has to fit Memcached's 250-byte limit");
    }

    /**
     * Builds a fetch request whose entity is resolved without a context, so the canonical
     * description can be computed in isolation.
     */
    private static function fetchRequest(int $batchSize): FetchRequest
    {
        $request = new FetchRequest("Person");
        $request->entity = self::entity();
        $request->fetchBatchSize = $batchSize;
        return $request;
    }

    // --- NullRowCache: the deliberate counter-example ---

    /**
     * NullRowCache disables caching, so it must accept every write and still report nothing —
     * a store configured with it has to keep working, just without a cache.
     */
    public function testNullRowCacheAcceptsWritesAndRemembersNothing(): void
    {
        $cache = new NullRowCache();
        $objectID = self::objectID("ignored");

        $cache->setSnapshot(self::snapshot("ignored"), $objectID);

        $this->assertFalse($cache->hasSnapshot($objectID), "the null cache never reports a hit");
        $this->assertNull($cache->snapshot($objectID), "and never returns a snapshot");
    }

    public function testNullRowCacheToleratesEveryBulkOperation(): void
    {
        $cache = new NullRowCache();
        $objectID = self::objectID("ignored");

        $cache->setSnapshots(new Dictionary(["key" => self::snapshot("ignored")]));
        $cache->deleteSnapshots(new ArrayClass([$objectID]));
        $cache->deleteSnapshot($objectID);
        $cache->deletePropertySnapshots(new Dictionary());

        $this->assertTrue($cache->snapshots(new ArrayClass([$objectID]))->isEmpty, "bulk reads come back empty rather than failing");
    }

    /**
     * Even disabled, the generation counter has to answer sensibly: a caller comparing
     * generations must not get a value that moves.
     */
    public function testNullRowCacheReportsAStableGeneration(): void
    {
        $cache = new NullRowCache();

        $before = $cache->currentGenerationForStore("any-store");
        $cache->advanceGenerationForStore("any-store");

        $this->assertSame($before, $cache->currentGenerationForStore("any-store"), "a disabled cache has no generation to advance");
    }

    /**
     * Every backend, caching or not, satisfies the interface the store programs against.
     */
    public function testEveryBackendImplementsTheCacheInterface(): void
    {
        foreach ([new DefaultRowCache(), new NullRowCache()] as $cache) {
            $this->assertInstanceOf(PersistentStoreCache::class, $cache);
        }
    }

    /**
     * Returns a real PropertyDescription to scope a snapshot with; the cache key uses only
     * its name, but the signature demands the real type.
     */
    private static function propertyNamed(string $name): PropertyDescription
    {
        $property = new AttributeDescription();
        $property->name = $name;
        $property->type = AttributeType::string;
        return $property;
    }
}
