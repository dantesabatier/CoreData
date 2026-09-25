<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DeleteRule;
use Sabatier\CoreData\DeleteRuleConflictDetector;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SnapshotProvider;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use const Sabatier\CoreData\ManagedObjectVersionKey;

/** @property string $code */
final class DenyLibrary extends ManagedObject
{
}

/** @property string $title */
final class DenyVolume extends ManagedObject
{
}

/**
 * A SnapshotProvider stub that answers the two methods separately, which
 * StubSnapshotProvider (in ConflictDetectionServiceTest) does not: the to-many branch of the
 * detector goes through snapshotWithExpressions and the to-one branch through snapshot, and
 * telling them apart is what these tests are about.
 */
final class BranchingSnapshotProvider implements SnapshotProvider
{
    /** @var int Number of snapshotWithExpressions() calls, so a test can prove the branch ran. */
    public int $expressionCalls = 0;

    /** @var int Number of snapshot() calls. */
    public int $plainCalls = 0;

    /**
     * @param Dictionary<mixed>|null $plain What snapshot() returns.
     * @param Dictionary<mixed>|null $withExpressions What snapshotWithExpressions() returns.
     */
    public function __construct(
        private readonly ?Dictionary $plain = null,
        private readonly ?Dictionary $withExpressions = null,
    ) {
    }

    #[Override]
    public function snapshot(ManagedObject $object, ArrayClass $properties): ?Dictionary
    {
        $this->plainCalls++;
        return $this->plain;
    }

    #[Override]
    public function snapshotWithExpressions(ManagedObject $object, ArrayClass $properties, ArrayClass $expressions): ?Dictionary
    {
        $this->expressionCalls++;
        return $this->withExpressions;
    }
}

/**
 * Tests for DeleteRuleConflictDetector, which decides whether deleting an object would violate a
 * Deny delete rule held by something else that still points at it.
 *
 * Worth covering carefully despite its size: it is the last gate before a delete is allowed
 * through, and its failure mode is silent in both directions. Miss a conflict and the framework
 * deletes a row another object still requires; invent one and a legitimate delete is refused
 * forever. Neither raises an error by itself.
 *
 * The subtlety the tests are built around is WHICH delete rule is consulted. The detector reads
 * `$relationship->inverseRelationship->deleteRule` — the rule on the far side, not the near one.
 * That matches how the framework treats delete rules elsewhere (see
 * project-fk-delete-rule-semantics: a foreign key's ON DELETE also comes from the inverse), and
 * it is the single easiest thing to get backwards here, so both directions are asserted.
 *
 * SnapshotProvider is an interface, so the store round trip is stubbed and these run without a
 * database; the model and objects are real, over an XML store, so entity metadata and
 * relationshipsByName behave as they do in production.
 */
final class DeleteRuleConflictDetectorTest extends TestCase
{
    private URL $storeURL;

    /**
     * A Library/Volume pair. The delete rules are the parameters of the model, because they are
     * what every test varies.
     *
     * Read the defaults with the inverse rule in mind: the detector consults
     * `$relationship->inverseRelationship->deleteRule`, so what protects a Library from deletion
     * is the rule on Volume.library — the far side of Library.volumes — not the rule on
     * Library.volumes itself. The defaults below put Deny there, which is why deleting a Library
     * is the conflicting case and deleting a Volume is not.
     *
     * @param DeleteRule $volumesRule The rule on Library.volumes, the to-many side.
     * @param DeleteRule $libraryRule The rule on Volume.library, the to-one side.
     */
    private static function model(
        DeleteRule $volumesRule = DeleteRule::nullifyDeleteRule,
        DeleteRule $libraryRule = DeleteRule::denyDeleteRule,
    ): ManagedObjectModel {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $volumes = new RelationshipDescription();
        $volumes->name = "volumes";
        $volumes->lazyDestinationEntityName = "DenyVolume";
        $volumes->lazyInverseRelationshipName = "library";
        $volumes->isToMany = true;
        $volumes->isOptional = true;
        $volumes->deleteRule = $volumesRule;

        $library = new EntityDescription();
        $library->name = "DenyLibrary";
        $library->managedObjectClassName = DenyLibrary::class;
        $library->properties = new ArrayClass([$code, $volumes]);

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $libraryRelationship = new RelationshipDescription();
        $libraryRelationship->name = "library";
        $libraryRelationship->lazyDestinationEntityName = "DenyLibrary";
        $libraryRelationship->lazyInverseRelationshipName = "volumes";
        $libraryRelationship->isOptional = true;
        $libraryRelationship->deleteRule = $libraryRule;

        $volume = new EntityDescription();
        $volume->name = "DenyVolume";
        $volume->managedObjectClassName = DenyVolume::class;
        $volume->properties = new ArrayClass([$title, $libraryRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$library, $volume]);
        return $model;
    }

    /** @throws Exception */
    private function context(ManagedObjectModel $model): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator($model);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * The baseline snapshot a delete carries: whatever the object looked like when it was loaded.
     * Only the version key is read by the detector, but the keys drive which properties it asks
     * the provider for.
     *
     * @return Dictionary<mixed>
     */
    private static function baseline(int $version = 1): Dictionary
    {
        return new Dictionary([ManagedObjectVersionKey => $version, "code" => "L-1"]);
    }

    /**
     * A store snapshot, as the provider would return it.
     *
     * @return Dictionary<mixed>
     */
    private static function storeSnapshot(int $version = 2, ?int $computedValue = null): Dictionary
    {
        $snapshot = new Dictionary([ManagedObjectVersionKey => $version, "code" => "L-1"]);
        if ($computedValue !== null) {
            $snapshot["computedValue"] = $computedValue;
        }
        return $snapshot;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");
    }

    /** @throws Exception */
    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    /**
     * The to-many side, the case the rule exists for: deleting a library whose volumes
     * relationship is denied, while the store still counts children, is a conflict.
     *
     * @throws Exception
     */
    public function testDenyWithRemainingChildrenIsAConflict(): void
    {
        $context = $this->context(self::model());
        $library = new DenyLibrary($context);
        $library->code = "L-1";

        $provider = new BranchingSnapshotProvider(withExpressions: self::storeSnapshot(computedValue: 3));
        $conflicts = new DeleteRuleConflictDetector($provider)->conflictsForDeletion($library, self::baseline());

        $this->assertSame(1, $conflicts->count, "children still reference the object, so the delete conflicts");
        $this->assertSame(1, $provider->expressionCalls, "the to-many branch counts children through an expression");
    }

    /**
     * The same model with nothing left pointing at the object: the count comes back zero and the
     * delete is allowed. This is the assertion that stops the detector from refusing every delete.
     *
     * @throws Exception
     */
    public function testDenyWithNoRemainingChildrenIsNotAConflict(): void
    {
        $context = $this->context(self::model());
        $library = new DenyLibrary($context);
        $library->code = "L-1";

        $provider = new BranchingSnapshotProvider(withExpressions: self::storeSnapshot(computedValue: 0));
        $conflicts = new DeleteRuleConflictDetector($provider)->conflictsForDeletion($library, self::baseline());

        $this->assertTrue($conflicts->isEmpty, "a denied relationship with no children does not block the delete");
    }

    /**
     * The count is a strict "> 0" test, so exactly one remaining child conflicts. Worth its own
     * case because an off-by-one here would let the last reference be orphaned.
     *
     * @throws Exception
     */
    public function testASingleRemainingChildIsEnoughToConflict(): void
    {
        $context = $this->context(self::model());
        $library = new DenyLibrary($context);
        $library->code = "L-1";

        $provider = new BranchingSnapshotProvider(withExpressions: self::storeSnapshot(computedValue: 1));
        $conflicts = new DeleteRuleConflictDetector($provider)->conflictsForDeletion($library, self::baseline());

        $this->assertSame(1, $conflicts->count);
    }

    /**
     * The rule consulted is the INVERSE relationship's. Library.volumes carries Deny here, and
     * the object being deleted is the Volume — whose own `library` rule is Nullify. The far side
     * is what decides, so this conflicts.
     *
     * @throws Exception
     */
    public function testTheInverseRulesDeleteRuleIsWhatCounts(): void
    {
        // Deny on the to-many side (Library.volumes) is the inverse of Volume.library, so it is
        // the VOLUME whose deletion it blocks — even though Volume.library itself says Nullify.
        $model = self::model(volumesRule: DeleteRule::denyDeleteRule, libraryRule: DeleteRule::nullifyDeleteRule);
        $context = $this->context($model);
        $volume = new DenyVolume($context);
        $volume->title = "V-1";

        $provider = new BranchingSnapshotProvider(plain: self::storeSnapshot());
        $conflicts = new DeleteRuleConflictDetector($provider)->conflictsForDeletion($volume, self::baseline());

        $this->assertSame(1, $conflicts->count, "Volume.library's inverse (Library.volumes) denies the delete");
        $this->assertSame(1, $provider->plainCalls, "the to-one branch reads a plain snapshot");
        $this->assertSame(0, $provider->expressionCalls, "and never counts children");
    }

    /**
     * The mirror of the previous case: with Deny moved to the to-one side, deleting the Library
     * is what conflicts and deleting the Volume is free. Asserting both directions is what
     * catches the rule being read off the near side instead of the inverse.
     *
     * @throws Exception
     */
    public function testMovingDenyToTheOtherSideMovesTheConflict(): void
    {
        $model = self::model(volumesRule: DeleteRule::denyDeleteRule, libraryRule: DeleteRule::nullifyDeleteRule);
        $context = $this->context($model);

        $library = new DenyLibrary($context);
        $library->code = "L-1";
        $volume = new DenyVolume($context);
        $volume->title = "V-1";

        $detector = new DeleteRuleConflictDetector(new BranchingSnapshotProvider(
            plain: self::storeSnapshot(),
            withExpressions: self::storeSnapshot(computedValue: 3),
        ));

        $this->assertTrue($detector->conflictsForDeletion($library, self::baseline())->isEmpty, "nothing denies the Library now");
        $this->assertSame(1, $detector->conflictsForDeletion($volume, self::baseline())->count, "the Volume is the protected side instead");
    }

    /**
     * Every delete rule that is not Deny is ignored outright — the detector's only job is Deny.
     * Cascade and Nullify have their own machinery, and No Action deliberately does nothing.
     *
     * @throws Exception
     */
    public function testNonDenyRulesNeverConflict(): void
    {
        foreach ([DeleteRule::nullifyDeleteRule, DeleteRule::cascadeDeleteRule, DeleteRule::noActionDeleteRule] as $rule) {
            $context = $this->context(self::model(libraryRule: $rule));
            $library = new DenyLibrary($context);
            $library->code = "L-1";

            $provider = new BranchingSnapshotProvider(
                plain: self::storeSnapshot(),
                withExpressions: self::storeSnapshot(computedValue: 3),
            );
            $conflicts = new DeleteRuleConflictDetector($provider)->conflictsForDeletion($library, self::baseline());

            $this->assertTrue($conflicts->isEmpty, "$rule->name does not deny a delete");
            $this->assertSame(0, $provider->expressionCalls, "and the store is not consulted at all for $rule->name");
        }
    }

    /**
     * An object the store no longer holds cannot be denied: something else deleted it first, so
     * there is nothing left to protect and the provider returns null.
     *
     * @throws Exception
     */
    public function testAnObjectMissingFromTheStoreIsNotAConflict(): void
    {
        $context = $this->context(self::model());
        $volume = new DenyVolume($context);
        $volume->title = "V-1";

        // The null is the subject of this case, not an omission, so it is named even though it is
        // also the default; Rector's RemoveNullNamedArgOnNullDefaultParamRector would drop it.
        $provider = new BranchingSnapshotProvider(plain: null);
        $conflicts = new DeleteRuleConflictDetector($provider)->conflictsForDeletion($volume, self::baseline());

        $this->assertTrue($conflicts->isEmpty, "a row that is already gone does not block its own delete");
    }

    /**
     * The conflict carries both versions and both snapshots, which is what a merge policy needs
     * to resolve it; a conflict with the versions swapped would make the policy reason backwards.
     * The computed count is stripped from the store snapshot first — it is the detector's own
     * scratch value, not part of the object.
     *
     * @throws Exception
     */
    public function testTheConflictCarriesBothVersionsAndDropsTheComputedCount(): void
    {
        $context = $this->context(self::model());
        $library = new DenyLibrary($context);
        $library->code = "L-1";

        $provider = new BranchingSnapshotProvider(withExpressions: self::storeSnapshot(version: 7, computedValue: 2));
        $conflict = new DeleteRuleConflictDetector($provider)
            ->conflictsForDeletion($library, self::baseline(version: 4))
            ->first;

        $this->assertNotNull($conflict);
        $this->assertSame(7, $conflict->newVersionNumber, "the store's version is the new one");
        $this->assertSame(4, $conflict->oldVersionNumber, "the baseline's version is the old one");
        $this->assertFalse($conflict->persistedSnapshot?->keys->containsElement("computedValue"), "the scratch count does not leak into the conflict");
    }

    /**
     * An entity without relationships has nothing that could deny a delete, so the detector does
     * no work at all. Cheap to assert and it pins that the scan is driven by relationshipsByName.
     *
     * @throws Exception
     */
    public function testAnEntityWithoutRelationshipsHasNothingToDeny(): void
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $lonely = new EntityDescription();
        $lonely->name = "DenyLibrary";
        $lonely->managedObjectClassName = DenyLibrary::class;
        $lonely->properties = new ArrayClass([$code]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$lonely]);

        $context = $this->context($model);
        $library = new DenyLibrary($context);
        $library->code = "L-1";

        $provider = new BranchingSnapshotProvider(plain: self::storeSnapshot());
        $conflicts = new DeleteRuleConflictDetector($provider)->conflictsForDeletion($library, self::baseline());

        $this->assertTrue($conflicts->isEmpty);
        $this->assertSame(0, $provider->plainCalls, "no relationships means no store lookups");
    }
}
