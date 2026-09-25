<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
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
use Sabatier\CoreData\MemcachedRowCache;
use Sabatier\CoreData\NullRowCache;
use Sabatier\CoreData\PersistentStoreCache;
use Sabatier\CoreData\PropertyDescription;
use Sabatier\CoreData\QueryGenerationToken;
use Sabatier\CoreData\RedisRowCache;
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
    /** @var string Distinguishes this run's keys from those a previous run left in a persistent backend. */
    private static string $nonce = "";

    #[Override]
    public static function setUpBeforeClass(): void
    {
        // uniqid() rather than random_bytes(): this only has to differ between runs, and the
        // CSPRNG version is declared to throw, which a PHPUnit hook cannot document away.
        self::$nonce = uniqid("", true);
    }

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
            "RedisRowCache" => [static fn(): ?RowCache =>
                self::canReach("redis", 6379) ? new RedisRowCache() : null,
            ],
            "MemcachedRowCache" => [static fn(): ?RowCache =>
                self::canReach("memcached", 11211) ? new MemcachedRowCache() : null,
            ],
        ];
    }

    /**
     * Whether a networked backend can actually be used: the extension is loaded and something
     * is listening on its port. The socket is probed rather than the client being constructed,
     * because both clients connect lazily — Memcached::addServer() never touches the network,
     * so a missing server would surface as a failed get() deep inside a test instead of a skip.
     */
    private static function canReach(string $extension, int $port): bool
    {
        if (!extension_loaded($extension)) {
            return false;
        }
        $socket = @fsockopen("127.0.0.1", $port, $errno, $error, 0.25);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
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

        /** @noinspection PhpObjectFieldsAreOnlyWrittenInspection */
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $entity;
    }

    /**
     * Object IDs carry a per-run nonce. Redis and Memcached outlive the PHP process — Redis
     * persists to disk, Memcached keeps its slabs — so a fixed reference makes every test that
     * asserts "nothing is cached yet" pass once and fail on the next run against whatever the
     * previous one left behind. The in-process backends do not care, and the nonce is invisible
     * to what is being asserted: only key identity matters, never the key's text.
     */
    private static function objectID(string $reference, string $entityName = "Person"): ManagedObjectID
    {
        return new ManagedObjectID(self::entity($entityName), "$reference-" . self::$nonce);
    }

    /**
     * A store identifier unique to this run, for the same reason object IDs carry a nonce: the
     * generation counter of a persistent backend outlives the process, and these tests assert
     * what an *uninitialized* store reports.
     */
    private static function storeIdentifier(string $label): string
    {
        return "store-$label-" . self::$nonce;
    }

    /** @return Dictionary<string> A one-attribute snapshot; the label identifies which write it came from. */
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
        /** @noinspection PhpRedundantOptionalArgumentInspection */
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
        $store = self::storeIdentifier(__FUNCTION__);

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
        $first = self::storeIdentifier("a-" . __FUNCTION__);
        $second = self::storeIdentifier("b-" . __FUNCTION__);

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
        $store = self::storeIdentifier(__FUNCTION__);

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
        $store = self::storeIdentifier(__FUNCTION__);

        $advanced = $cache->advanceGenerationForStore($store);

        $expected = $cache instanceof DefaultRowCache || $cache instanceof MemcachedRowCache ? 2 : 1;
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

    // --- Bulk operations ---
    //
    // The store never caches one row at a time on the hot paths: a batch insert, a save and a
    // batch fault all hand the cache a whole Dictionary keyed by object-ID URI (SQLCore lines
    // 291, 332, 368, 421, 456). So the contract these assert is the one SQLCore actually
    // depends on: the key is `$objectID->uriRepresentation()->absoluteString`, and what
    // setSnapshots() writes under it, snapshots() reads back under the same key.

    /**
     * The bulk round-trip, and the key it is keyed by. SQLCore builds the Dictionary it passes
     * to setSnapshots() with the object ID's URI as the key, then expects snapshots() to answer
     * under that same URI — not under the cache's internal key, which for a property-scoped
     * entry would differ.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkSnapshotsRoundTripKeyedByObjectIDURI(callable $factory): void
    {
        $cache = $this->backend($factory);
        $first = self::objectID("bulk-first");
        $second = self::objectID("bulk-second");

        $cache->setSnapshots(new Dictionary([
            $first->uriRepresentation()->absoluteString => self::snapshot("one"),
            $second->uriRepresentation()->absoluteString => self::snapshot("two"),
        ]));

        $snapshots = $cache->snapshots(new ArrayClass([$first, $second]));

        $this->assertSame("one", $snapshots[$first->uriRepresentation()->absoluteString]?->offsetGet("label"));
        $this->assertSame("two", $snapshots[$second->uriRepresentation()->absoluteString]?->offsetGet("label"));
    }

    /**
     * A bulk read is not all-or-nothing: the store asks for the IDs it wants and takes whatever
     * is cached, fetching the rest from the database. A backend that failed the whole read
     * because one ID was cold would send every fault to the database.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkReadReturnsOnlyTheEntriesThatAreCached(callable $factory): void
    {
        $cache = $this->backend($factory);
        $cached = self::objectID("bulk-present");
        $cold = self::objectID("bulk-absent");

        $cache->setSnapshots(new Dictionary([$cached->uriRepresentation()->absoluteString => self::snapshot("present")]));

        $snapshots = $cache->snapshots(new ArrayClass([$cached, $cold]));

        $this->assertSame("present", $snapshots[$cached->uriRepresentation()->absoluteString]?->offsetGet("label"));
        $this->assertNull($snapshots[$cold->uriRepresentation()->absoluteString], "an uncached ID is absent from the result rather than present-and-null");
        $this->assertFalse($snapshots->keys->containsElement($cold->uriRepresentation()->absoluteString), "and its key is not in the result at all");
    }

    /**
     * Asking for nothing returns nothing. The guard matters because two backends short-circuit
     * on it before touching the network: apcu_fetch([]) and a pipelined MGET with no keys are
     * both wasted round trips at best.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkReadOfNoObjectIDsIsEmpty(callable $factory): void
    {
        $cache = $this->backend($factory);

        $this->assertTrue($cache->snapshots(new ArrayClass())->isEmpty);
    }

    /**
     * A bulk read where nothing is cached is empty, not a Dictionary of nulls. SQLCore decides
     * which IDs still need a database round trip by subtracting what came back, so a result
     * padded with null keys would report every cold ID as already cached.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkReadWithNothingCachedIsEmpty(callable $factory): void
    {
        $cache = $this->backend($factory);

        $snapshots = $cache->snapshots(new ArrayClass([self::objectID("bulk-never-written")]));

        $this->assertTrue($snapshots->isEmpty, "a fully cold bulk read comes back empty");
    }

    /**
     * Writing nothing is a no-op rather than an error. A save with no inserted objects reaches
     * setSnapshots() with an empty Dictionary.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkWriteOfNoSnapshotsIsANoOp(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("bulk-untouched");
        $cache->setSnapshot(self::snapshot("kept"), $objectID);

        $cache->setSnapshots(new Dictionary());

        $this->assertSame("kept", $cache->snapshot($objectID)?->offsetGet("label"), "an empty bulk write disturbs nothing");
    }

    /**
     * A bulk write overwrites an existing entry rather than merging with it — a save writes the
     * object's current snapshot, and a stale attribute surviving underneath it would be served
     * to the next fault.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkWriteReplacesAnExistingSnapshot(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("bulk-overwrite");
        $uri = $objectID->uriRepresentation()->absoluteString;

        $cache->setSnapshots(new Dictionary([$uri => new Dictionary(["label" => "before", "dropped" => "gone"])]));
        $cache->setSnapshots(new Dictionary([$uri => self::snapshot("after")]));

        $snapshot = $cache->snapshots(new ArrayClass([$objectID]))[$uri];

        $this->assertSame("after", $snapshot?->offsetGet("label"));
        $this->assertNull($snapshot?->offsetGet("dropped"), "the replaced snapshot does not leave its old keys behind");
    }

    /**
     * A bulk write is visible to the single-object read, and vice versa: they address the same
     * entry. SQLCore mixes the two freely — it writes a batch after a save and then faults one
     * object at a time through snapshot().
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkAndSingleOperationsShareTheSameEntries(callable $factory): void
    {
        $cache = $this->backend($factory);
        $written = self::objectID("bulk-then-single");
        $single = self::objectID("single-then-bulk");

        $cache->setSnapshots(new Dictionary([$written->uriRepresentation()->absoluteString => self::snapshot("bulk")]));
        $cache->setSnapshot(self::snapshot("single"), $single);

        $this->assertSame("bulk", $cache->snapshot($written)?->offsetGet("label"), "a bulk write is readable one object at a time");
        $this->assertTrue($cache->hasSnapshot($written), "and hasSnapshot agrees with it");
        $this->assertSame("single", $cache->snapshots(new ArrayClass([$single]))[$single->uriRepresentation()->absoluteString]?->offsetGet("label"), "a single write is readable in bulk");
    }

    /**
     * Bulk deletion removes exactly the objects named and leaves the others cached. This is the
     * invalidation path for an update: SQLCore deletes the snapshots of the objects a save
     * touched, and over-deleting would cost the cache while under-deleting would serve stale rows.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkDeleteRemovesOnlyTheObjectsNamed(callable $factory): void
    {
        $cache = $this->backend($factory);
        $removed = self::objectID("bulk-removed");
        $kept = self::objectID("bulk-kept");

        $cache->setSnapshots(new Dictionary([
            $removed->uriRepresentation()->absoluteString => self::snapshot("removed"),
            $kept->uriRepresentation()->absoluteString => self::snapshot("kept"),
        ]));

        $cache->deleteSnapshots(new ArrayClass([$removed]));

        $this->assertFalse($cache->hasSnapshot($removed), "the named object is evicted");
        $this->assertTrue($cache->hasSnapshot($kept), "an object not named survives");
    }

    /**
     * Deleting nothing is a no-op. A save that touched no existing object reaches
     * deleteSnapshots() with an empty collection.
     *
     * The backends' `isEmpty` guards survive mutation — removing one breaks no test — and that
     * is a real limit of testing through the interface rather than an untested branch. They
     * exist because `Redis::del([])` and `mget([])` are malformed commands that return `false`
     * instead of raising, and `Memcached` behaves likewise; the failure is a silent wasted round
     * trip, which no assertion about cache contents can observe. Verified against live servers
     * on 2026-09-14. Do not delete the guards on the strength of coverage.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkDeleteOfNoObjectIDsIsANoOp(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("bulk-delete-untouched");
        $cache->setSnapshot(self::snapshot("kept"), $objectID);

        $cache->deleteSnapshots(new ArrayClass());

        $this->assertTrue($cache->hasSnapshot($objectID), "an empty bulk delete evicts nothing");
    }

    /**
     * Deleting a cold entry is silent. The store does not check before invalidating, so this is
     * the ordinary case after an object was evicted by TTL.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testBulkDeleteOfUncachedObjectsIsSilent(callable $factory): void
    {
        $cache = $this->backend($factory);

        $cache->deleteSnapshots(new ArrayClass([self::objectID("bulk-delete-never-written")]));

        $this->assertTrue(true, "deleting what was never cached is not an error");
    }

    /**
     * Property snapshots are deleted by URI and property name together. This is the
     * relationship-invalidation path: when a save changes a relationship, SQLCore drops the
     * cached relationship for that object (line 371) while leaving the object's own snapshot,
     * which it has just rewritten, in place.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testDeletePropertySnapshotsRemovesTheScopedEntryOnly(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("property-bulk");
        $relationship = self::propertyNamed("label");
        $other = self::propertyNamed("untouched");

        $cache->setSnapshot(self::snapshot("object"), $objectID);
        $cache->setSnapshot(self::snapshot("relationship"), $objectID, 3600, $relationship);
        $cache->setSnapshot(self::snapshot("other"), $objectID, 3600, $other);

        $cache->deletePropertySnapshots(new Dictionary([
            $objectID->uriRepresentation()->absoluteString => new ArrayClass([$relationship]),
        ]));

        $this->assertFalse($cache->hasSnapshot($objectID, $relationship), "the named relationship is invalidated");
        $this->assertTrue($cache->hasSnapshot($objectID, $other), "a relationship not named survives");
        $this->assertTrue($cache->hasSnapshot($objectID), "and the object's own snapshot is untouched");
    }

    /**
     * One object can have several relationships invalidated at once, and several objects can be
     * named in the same call — SQLCore accumulates a Dictionary of URI to changed relationships
     * across every updated object in the save.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testDeletePropertySnapshotsSpansObjectsAndProperties(callable $factory): void
    {
        $cache = $this->backend($factory);
        $first = self::objectID("property-span-first");
        $second = self::objectID("property-span-second");
        $left = self::propertyNamed("left");
        $right = self::propertyNamed("right");

        foreach ([$first, $second] as $objectID) {
            $cache->setSnapshot(self::snapshot("left"), $objectID, 3600, $left);
            $cache->setSnapshot(self::snapshot("right"), $objectID, 3600, $right);
        }

        $cache->deletePropertySnapshots(new Dictionary([
            $first->uriRepresentation()->absoluteString => new ArrayClass([$left, $right]),
            $second->uriRepresentation()->absoluteString => new ArrayClass([$left]),
        ]));

        $this->assertFalse($cache->hasSnapshot($first, $left), "both of the first object's relationships go");
        $this->assertFalse($cache->hasSnapshot($first, $right));
        $this->assertFalse($cache->hasSnapshot($second, $left), "the second object's named relationship goes");
        $this->assertTrue($cache->hasSnapshot($second, $right), "and the one not named survives");
    }

    /**
     * Deleting no property snapshots is a no-op: a save that changed only attributes reaches
     * this path with an empty Dictionary.
     *
     * @param callable(): ?RowCache $factory
     */
    #[DataProvider("cachingBackends")]
    public function testDeletePropertySnapshotsOfNothingIsANoOp(callable $factory): void
    {
        $cache = $this->backend($factory);
        $objectID = self::objectID("property-bulk-untouched");
        $property = self::propertyNamed("label");
        $cache->setSnapshot(self::snapshot("kept"), $objectID, 3600, $property);

        $cache->deletePropertySnapshots(new Dictionary());

        $this->assertTrue($cache->hasSnapshot($objectID, $property), "an empty property invalidation evicts nothing");
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
        /** @noinspection PhpExpressionResultUnusedInspection */
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
