<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\CustomMigrationStage;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\LightweightMigrationStage;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\ManagedObjectModelReference;
use Sabatier\CoreData\MigrationStage;
use Sabatier\CoreData\StagedMigrationManager;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Error;
use PHPUnit\Framework\TestCase;

/**
 * Covers the stage validation of StagedMigrationManager, which sat at 48.6% with every rejection
 * branch unexercised.
 *
 * A staged migration is a chain: each stage ends at a model version, and the next stage has to
 * begin at that same version. Validation is what refuses a chain with a gap in it, and a gap is
 * precisely the case that must not proceed — a migration that skipped a version would run the
 * wrong transformations against the data and there would be no error to point at afterwards.
 *
 * No store is involved. The checksums are opaque strings as far as this logic is concerned, so
 * the tests use readable ones rather than real version hashes; what is asserted is how they are
 * chained, which is the whole of the contract.
 */
final class StagedMigrationStageValidationTest extends TestCase
{
    /** A model reference carrying the given checksum. The model itself is never read by validation. */
    private static function reference(string $checksum): ManagedObjectModelReference
    {
        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = "StageRow";
        $entity->properties = new ArrayClass([$label]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return new ManagedObjectModelReference($model, $checksum);
    }

    private static function custom(string $from, string $to): CustomMigrationStage
    {
        return new CustomMigrationStage(self::reference($from), self::reference($to));
    }

    /**
     * @param list<string> $checksums
     */
    private static function lightweight(array $checksums): LightweightMigrationStage
    {
        return new LightweightMigrationStage(new ArrayClass($checksums));
    }

    /**
     * @param list<MigrationStage> $stages
     */
    private static function manager(array $stages): StagedMigrationManager
    {
        return new StagedMigrationManager(new ArrayClass($stages));
    }

    // --- Accepted chains ---

    public function testASingleCustomStageIsValid(): void
    {
        $error = null;

        $this->assertTrue(self::manager([self::custom("v1", "v2")])->validateStages($error));
        $this->assertNull($error, "a valid chain reports no error");
    }

    public function testASingleLightweightStageIsValid(): void
    {
        $error = null;

        $this->assertTrue(self::manager([self::lightweight(["v1", "v2"])])->validateStages($error));
        $this->assertNull($error);
    }

    /**
     * Two custom stages chain when the first ends where the second begins.
     */
    public function testConsecutiveCustomStagesChain(): void
    {
        $error = null;

        $this->assertTrue(self::manager([self::custom("v1", "v2"), self::custom("v2", "v3")])->validateStages($error));
        $this->assertNull($error);
    }

    /**
     * A lightweight stage chains on its FIRST checksum and hands on its LAST — it stands for a
     * run of versions the framework can migrate without help, so the chain enters at one end and
     * leaves at the other.
     */
    public function testALightweightStageChainsFromItsFirstChecksumToItsLast(): void
    {
        $error = null;
        $stages = [
            self::custom("v1", "v2"),
            self::lightweight(["v2", "v3", "v4"]),
            self::custom("v4", "v5"),
        ];

        $this->assertTrue(self::manager($stages)->validateStages($error), "the lightweight run bridges v2 to v4");
        $this->assertNull($error);
    }

    /**
     * The first stage is not checked against anything: there is no predecessor to match, so a
     * chain may start at whatever version the store happens to be at.
     */
    public function testTheFirstStageIsNotConstrained(): void
    {
        $error = null;

        $this->assertTrue(self::manager([self::custom("anything", "v2"), self::custom("v2", "v3")])->validateStages($error));
        $this->assertNull($error);
    }

    // --- Rejected chains ---

    /**
     * A manager with no stages has nothing to migrate through, which is an error rather than a
     * vacuous success: the caller asked for a staged migration and gave no stages.
     */
    public function testNoStagesIsRejected(): void
    {
        $error = null;

        $this->assertFalse(self::manager([])->validateStages($error));
        $this->assertInstanceOf(Error::class, $error, "the rejection is reported through the error out-parameter");
    }

    /**
     * The gap case, and the reason validation exists. If the second stage does not begin where
     * the first ended, the versions between them would never be migrated — the data would be
     * transformed as though it had already passed through a step it never did.
     */
    public function testAGapBetweenCustomStagesIsRejected(): void
    {
        $error = null;

        $this->assertFalse(self::manager([self::custom("v1", "v2"), self::custom("v3", "v4")])->validateStages($error));
        $this->assertInstanceOf(Error::class, $error);
    }

    /**
     * The same gap, seen from a lightweight stage: it has to begin at the version the previous
     * stage ended on.
     */
    public function testALightweightStageStartingElsewhereIsRejected(): void
    {
        $error = null;

        $this->assertFalse(self::manager([self::custom("v1", "v2"), self::lightweight(["v9", "v10"])])->validateStages($error));
        $this->assertInstanceOf(Error::class, $error);
    }

    /**
     * And a custom stage that does not pick up where a lightweight run left off.
     */
    public function testACustomStageMissingTheLightweightEndIsRejected(): void
    {
        $error = null;
        $stages = [
            self::lightweight(["v1", "v2", "v3"]),
            self::custom("v2", "v4"),
        ];

        $this->assertFalse(self::manager($stages)->validateStages($error), "the custom stage must begin at v3, the run's last version");
        $this->assertInstanceOf(Error::class, $error);
    }

    /**
     * A lightweight stage naming no versions is rejected outright: it claims to bridge a run of
     * models and names none, so it can neither be entered nor left.
     */
    public function testAnEmptyLightweightStageIsRejected(): void
    {
        $error = null;

        $this->assertFalse(self::manager([self::lightweight([])])->validateStages($error));
        $this->assertInstanceOf(Error::class, $error);
    }

    /**
     * The empty-stage rejection applies wherever it sits in the chain, not only first.
     */
    public function testAnEmptyLightweightStageIsRejectedMidChain(): void
    {
        $error = null;

        $this->assertFalse(self::manager([self::custom("v1", "v2"), self::lightweight([])])->validateStages($error));
        $this->assertInstanceOf(Error::class, $error);
    }

    // --- Locating the store's position in the chain ---

    /**
     * The store's current model has to be found among the stages before a migration can start,
     * and the index is where the run begins.
     */
    public function testAChecksumIsFoundInTheStageThatCarriesIt(): void
    {
        $manager = self::manager([self::custom("v1", "v2"), self::lightweight(["v2", "v3"])]);

        $this->assertSame(0, $manager->findCurrentMigrationStageFromModelChecksum("v1"), "a custom stage matches on either of its models");
        $this->assertSame(0, $manager->findCurrentMigrationStageFromModelChecksum("v2"), "and the first stage wins a checksum two stages share");
        $this->assertSame(1, $manager->findCurrentMigrationStageFromModelChecksum("v3"), "a lightweight stage matches any version it names");
    }

    /**
     * A checksum belonging to no stage reports -1 rather than defaulting to the start: a store
     * at an unknown version must not be migrated as though it were at the first one.
     */
    public function testAnUnknownChecksumIsNotFound(): void
    {
        $manager = self::manager([self::custom("v1", "v2")]);

        $this->assertSame(-1, $manager->findCurrentMigrationStageFromModelChecksum("unknown"));
    }

    // --- Deciding whether to attempt a staged migration ---

    /**
     * A store already at the coordinator's version needs no migration, and this answers before
     * validating anything — there is nothing to check.
     */
    public function testAStoreAtTheCoordinatorsVersionIsNotMigrated(): void
    {
        $error = null;
        $manager = self::manager([self::custom("v1", "v2")]);

        $this->assertFalse($manager->shouldAttemptStagedMigrationWithStoreModelVersionChecksum("v2", "v2", $error));
        $this->assertNull($error, "declining because nothing is needed is not an error");
    }

    /**
     * A store at a version the chain covers, with the coordinator elsewhere, is migrated.
     */
    public function testAStoreAtAKnownVersionIsMigrated(): void
    {
        $error = null;
        $manager = self::manager([self::custom("v1", "v2")]);

        $this->assertTrue($manager->shouldAttemptStagedMigrationWithStoreModelVersionChecksum("v1", "v2", $error));
        $this->assertNull($error);
    }

    /**
     * A store at a version no stage names is refused WITH an error, unlike the no-work case: the
     * caller configured a chain that cannot reach this store, which it needs to be told about.
     */
    public function testAStoreAtAnUnknownVersionIsRefusedWithAnError(): void
    {
        $error = null;
        $manager = self::manager([self::custom("v1", "v2")]);

        $this->assertFalse($manager->shouldAttemptStagedMigrationWithStoreModelVersionChecksum("v7", "v2", $error));
        $this->assertInstanceOf(Error::class, $error, "an unreachable store is an error, not a quiet decline");
    }

    /**
     * An invalid chain is refused before the store's position is even looked for — validation
     * comes first, so a broken configuration is reported as such rather than as a missing store.
     */
    public function testAnInvalidChainIsRefusedBeforeLookingForTheStore(): void
    {
        $error = null;
        $manager = self::manager([self::custom("v1", "v2"), self::custom("v3", "v4")]);

        $this->assertFalse($manager->shouldAttemptStagedMigrationWithStoreModelVersionChecksum("v1", "v4", $error));
        $this->assertInstanceOf(Error::class, $error);
    }
}
