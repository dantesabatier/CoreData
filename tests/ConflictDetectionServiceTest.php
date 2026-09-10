<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\ConflictDetectionService;
use Sabatier\CoreData\DeleteRuleConflictDetector;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MergePolicy;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreSnapshotProvider;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\SnapshotProvider;
use Sabatier\CoreData\SnapshotVersioningStrategy;
use Sabatier\CoreData\VersioningStrategy;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\ManagedObjectVersionKey;

/**
 * @property int $balance
 * @property string $email
 */
final class Account extends ManagedObject
{
}

/**
 * A SnapshotProvider stub that returns a preset store snapshot (or null). Used to drive the
 * optimistic-locking path of detectConflicts without a store round trip.
 */
final class StubSnapshotProvider implements SnapshotProvider
{
    /** @param Dictionary<mixed>|null $result */
    public function __construct(private ?Dictionary $result = null)
    {
    }

    #[Override]
    public function snapshot(ManagedObject $object, ArrayClass $properties): ?Dictionary
    {
        return $this->result;
    }

    #[Override]
    public function snapshotWithExpressions(ManagedObject $object, ArrayClass $properties, ArrayClass $expressions): ?Dictionary
    {
        return $this->result;
    }
}

/** A VersioningStrategy stub with a fixed verdict. */
final class StubVersioningStrategy implements VersioningStrategy
{
    public function __construct(private bool $conflict)
    {
    }

    #[Override]
    public function hasConflict(Dictionary $baseline, Dictionary $store): bool
    {
        return $this->conflict;
    }
}

/**
 * Tests for ConflictDetectionService — the orchestrator that decides when a save raises a merge,
 * optimistic-locking, or uniqueness conflict. It is dense with branching and its failure mode is
 * the worst kind for a persistence framework: a missed conflict silently lets duplicate or stale
 * data through. It had no coverage.
 *
 * Approach: a real XML-backed stack provides genuine ManagedObject/context/fetch behavior (so
 * uniqueIndexedAttributeNames, the constraint fetch, conflictingPendingPeer and valuesCollide run
 * for real), while the three injected collaborators are stubs where a specific branch needs to be
 * driven deterministically. MergePolicy is final (not mockable): the error policy is used to turn
 * "a conflict was detected" into an observable throw, and a real merge policy is used where the
 * assertion is "no conflict, so nothing is raised".
 *
 * Model: Account with a unique "email" (via uniquenessConstraints) and a non-unique "balance".
 */
final class ConflictDetectionServiceTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $email = new AttributeDescription();
        $email->name = "email";
        $email->type = AttributeType::string;

        $balance = new AttributeDescription();
        $balance->name = "balance";
        $balance->type = AttributeType::integer32;

        $account = new EntityDescription();
        $account->name = "Account";
        $account->managedObjectClassName = Account::class;
        $account->properties = new ArrayClass([$email, $balance]);
        // Declare "email" as unique through a uniqueness constraint (the branch that reading
        // $indexes alone would miss).
        $account->uniquenessConstraints = new ArrayClass([new ArrayClass(["email"])]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$account]);
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

    /**
     * Builds the service. Each collaborator defaults to a benign stub; a test overrides only the
     * one it is exercising.
     */
    private function service(
        MergePolicy $policy,
        ?SnapshotProvider $snapshotProvider = null,
        ?VersioningStrategy $versioningStrategy = null,
    ): ConflictDetectionService {
        $snapshotProvider ??= new StubSnapshotProvider(null);
        $versioningStrategy ??= new StubVersioningStrategy(false);
        return new ConflictDetectionService(
            $snapshotProvider,
            $versioningStrategy,
            new DeleteRuleConflictDetector($snapshotProvider),
            $policy,
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-conflict-test-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    #[Override]
    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    // --- detectConstraintConflicts ---

    public function testDuplicateAgainstAStoredRowRaisesAConstraintConflict(): void
    {
        // Seed a stored row with email "a@x.com".
        $seed = $this->context();
        $first = new Account($seed);
        $first->email = "a@x.com";
        $seed->save();

        // A new object in a fresh context reuses that email → conflict against the stored row.
        $context = $this->context();
        $dup = new Account($context);
        $dup->email = "a@x.com";
        $dup->balance = 5;

        $service = $this->service(MergePolicy::error());
        $this->expectException(InternalInconsistencyException::class);
        $service->detectConstraintConflicts($dup);
    }

    public function testUniqueValuePassesWithoutConflict(): void
    {
        $seed = $this->context();
        $first = new Account($seed);
        $first->email = "taken@x.com";
        $seed->save();

        $context = $this->context();
        $ok = new Account($context);
        $ok->email = "free@x.com";

        // A real (non-error) policy: with no conflict, resolveConstraintConflicts is never reached,
        // so nothing throws and the call completes.
        $service = $this->service(MergePolicy::mergeByPropertyStoreTrump());
        $service->detectConstraintConflicts($ok);
        $this->addToAssertionCount(1);
    }

    public function testDuplicateAgainstAPendingPeerRaisesAConstraintConflict(): void
    {
        // Two brand-new objects in the SAME save sharing an email. Neither is in the store yet, so
        // only the pending-peer check (conflictingPendingPeer) can catch this.
        $context = $this->context();
        $a = new Account($context);
        $a->email = "dup@x.com";
        $b = new Account($context);
        $b->email = "dup@x.com";

        $service = $this->service(MergePolicy::error());
        $this->expectException(InternalInconsistencyException::class);
        $service->detectConstraintConflicts($a);
    }

    public function testPendingPeerCollisionIsCaseInsensitiveForStrings(): void
    {
        // valuesCollide mirrors the store's LIKE comparison for strings: case-insensitive.
        $context = $this->context();
        $a = new Account($context);
        $a->email = "Case@X.com";
        $b = new Account($context);
        $b->email = "case@x.com";

        $service = $this->service(MergePolicy::error());
        $this->expectException(InternalInconsistencyException::class);
        $service->detectConstraintConflicts($b);
    }

    public function testDeletedPeerDoesNotCollide(): void
    {
        // A peer that shares the value but is being deleted frees its value → no conflict.
        $context = $this->context();
        $a = new Account($context);
        $a->email = "gone@x.com";
        $b = new Account($context);
        $b->email = "gone@x.com";
        $context->delete($a);

        $service = $this->service(MergePolicy::error());
        $service->detectConstraintConflicts($b);
        $this->addToAssertionCount(1); // no throw: the deleted peer does not count
    }

    public function testNoUniqueAttributesIsANoOp(): void
    {
        // An entity with no unique index/constraint: detectConstraintConflicts returns immediately,
        // so even the error policy never fires.
        $model = self::model();
        $model->entitiesByName["Account"]->uniquenessConstraints = new ArrayClass();
        $coordinator = new PersistentStoreCoordinator($model);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;

        $account = new Account($context);
        $account->email = "whatever@x.com";

        $service = $this->service(MergePolicy::error());
        $service->detectConstraintConflicts($account);
        $this->addToAssertionCount(1);
    }

    // --- detectConflicts (optimistic locking) ---

    public function testTemporaryIdObjectIsSkipped(): void
    {
        $context = $this->context();
        $fresh = new Account($context);
        $fresh->email = "temp@x.com";
        $fresh->originalSnapshot = new Dictionary([ManagedObjectVersionKey => 1]);

        $service = $this->service(
            MergePolicy::error(),
            new StubSnapshotProvider(new Dictionary([ManagedObjectVersionKey => 2])),
            new StubVersioningStrategy(true),
        );
        $service->detectConflicts($fresh);
        $this->addToAssertionCount(1);
    }

    public function testMissingOriginalSnapshotIsSkipped(): void
    {
        $context = $this->context();
        $account = new Account($context);
        $account->email = "nosnap@x.com";
        $account->balance = 1;
        $context->save();
        $account->balance = 2;
        $context->processPendingChanges();
        $this->assertNull($account->originalSnapshot, "precondition: an inserted-then-mutated object has no originalSnapshot");

        $service = $this->service(
            MergePolicy::error(),
            new StubSnapshotProvider(new Dictionary([ManagedObjectVersionKey => 99])),
            new StubVersioningStrategy(true),
        );
        $service->detectConflicts($account);
        $this->addToAssertionCount(1);
    }

    public function testVersionConflictOnAnUpdatedObjectRaises(): void
    {
        $context = $this->context();
        $account = new Account($context);
        $account->email = "v@x.com";
        $account->balance = 1;
        $context->save();

        $account->originalSnapshot = $account->dictionaryWithValues(new ArrayClass(["email", "balance", ManagedObjectVersionKey]));
        $account->balance = 2;
        $context->processPendingChanges();
        $this->assertTrue($account->isUpdated, "precondition: the object is in the updated set");

        $service = $this->service(
            MergePolicy::error(),
            new StubSnapshotProvider(new Dictionary([ManagedObjectVersionKey => 99])),
            new StubVersioningStrategy(true),
        );
        $this->expectException(InternalInconsistencyException::class);
        $service->detectConflicts($account);
    }

    public function testNoVersionConflictLeavesTheSaveAlone(): void
    {
        $context = $this->context();
        $account = new Account($context);
        $account->email = "quiet@x.com";
        $account->balance = 1;
        $context->save();

        $account->originalSnapshot = $account->dictionaryWithValues(new ArrayClass(["email", "balance", ManagedObjectVersionKey]));
        $account->balance = 2;
        $context->processPendingChanges();

        $service = $this->service(
            MergePolicy::error(),
            new StubSnapshotProvider(new Dictionary([ManagedObjectVersionKey => 1])),
            new StubVersioningStrategy(false),
        );
        $service->detectConflicts($account);
        $this->addToAssertionCount(1);
    }

    public function testAtomicStoreOptimisticLockingDegradesToNoConflict(): void
    {
        $seed = $this->context();
        $seeded = new Account($seed);
        $seeded->email = "row@x.com";
        $seeded->balance = 1;
        $seed->save();

        $context = $this->context();
        $account = $context->fetch(Account::fetchRequest())->first;
        $account->balance = 2;
        $context->processPendingChanges();

        $snapshotProvider = new PersistentStoreSnapshotProvider($context);
        $service = new ConflictDetectionService(
            $snapshotProvider,
            new SnapshotVersioningStrategy(),
            new DeleteRuleConflictDetector($snapshotProvider),
            MergePolicy::error(),
        );
        $service->detectConflicts($account);
        $this->addToAssertionCount(1);
    }
}
