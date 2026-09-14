<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MigrationManager;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\StoreMigrationPolicy;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\InferMappingModelAutomaticallyOption;

/**
 * Covers StoreMigrationPolicy, the value object that decides how a store migration is set up. It
 * was at 0%: nothing in src/ constructs one, so it is reached only by a caller assembling a
 * migration itself.
 *
 * The decision worth pinning is which mapping model a migration runs under. With the inference
 * option the policy derives one by comparing the two models; without it, it demands a mapping
 * model someone authored, and refuses rather than silently inferring. That distinction is the
 * difference between a migration the developer reviewed and one the framework guessed — and
 * [[project-public-release-readiness]] records that guessing is exactly what must not happen
 * where a custom mapping was intended.
 *
 * No store is involved: these assert how a migration is configured, not what running it does.
 */
final class StoreMigrationPolicyTest extends TestCase
{
    /** A one-entity model, optionally carrying an extra attribute so two models can differ. */
    private static function model(?string $extraAttributeName = null): ManagedObjectModel
    {
        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $properties = new ArrayClass([$label]);
        if ($extraAttributeName !== null) {
            $extra = new AttributeDescription();
            $extra->name = $extraAttributeName;
            $extra->type = AttributeType::string;
            $properties->append($extra);
        }

        $entity = new EntityDescription();
        $entity->name = "PolicyRow";
        $entity->properties = $properties;

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    private static function policy(?Dictionary $destinationOptions = null): StoreMigrationPolicy
    {
        $policy = new StoreMigrationPolicy();
        $policy->destinationOptions = $destinationOptions;
        return $policy;
    }

    /**
     * The policy builds the manager that will run the migration, over the two models it is given.
     */
    public function testItCreatesAMigrationManagerForTheModelPair(): void
    {
        $this->assertInstanceOf(
            MigrationManager::class,
            self::policy()->createMigrationManager(self::model(), self::model("added")),
        );
    }

    /**
     * With the inference option set, the policy derives a mapping model from the difference
     * between the two models — one entity mapping per entity it has to carry across.
     *
     * @throws Exception
     */
    public function testTheInferenceOptionYieldsADerivedMappingModel(): void
    {
        $policy = self::policy(new Dictionary([InferMappingModelAutomaticallyOption => true]));

        $mappingModel = $policy->mappingModel(self::model(), self::model("added"));

        $this->assertSame(1, $mappingModel->entityMappings->count, "the one entity is mapped across");
    }

    /**
     * Without the option, the policy looks for a mapping model somebody authored and refuses
     * when there is none. It does NOT fall back to inference: a migration that was meant to run
     * under a reviewed mapping must not quietly run under a guessed one.
     *
     * @throws Exception
     */
    public function testWithoutTheOptionAMissingMappingModelIsRefused(): void
    {
        $policy = self::policy(new Dictionary());

        $this->expectException(InternalInconsistencyException::class);
        $policy->mappingModel(self::model(), self::model("added"));
    }

    /**
     * Absent options behave as absent inference, not as inference enabled — the default is the
     * conservative one.
     *
     * @throws Exception
     */
    public function testAbsentOptionsAlsoRefuseToInfer(): void
    {
        $policy = self::policy();

        $this->expectException(InternalInconsistencyException::class);
        $policy->mappingModel(self::model(), self::model("added"));
    }

    /**
     * Inference works over identical models too, which is the no-op migration a coordinator runs
     * when a store is already at the destination version.
     *
     * @throws Exception
     */
    public function testInferenceOverIdenticalModelsStillMapsTheEntities(): void
    {
        $policy = self::policy(new Dictionary([InferMappingModelAutomaticallyOption => true]));

        $mappingModel = $policy->mappingModel(self::model(), self::model());

        $this->assertSame(1, $mappingModel->entityMappings->count);
    }

    /**
     * The two migration hooks do nothing by default. They exist for a caller to observe the
     * migration around the manager's run, so the base has to tolerate being called.
     */
    public function testTheMigrationHooksAreNoOpsByDefault(): void
    {
        $policy = self::policy();
        $manager = $policy->createMigrationManager(self::model(), self::model("added"));

        $policy->willPerformMigrationWithManager($manager);
        $policy->didPerformMigrationWithManager($manager);

        $this->assertNull($policy->destinationOptions, "the policy is unchanged by the notifications");
    }

    /**
     * Migrating without a coordinator fails immediately and says so. The policy holds the
     * coordinator it migrates through, and there is no sensible default: proceeding would mean
     * migrating a store nobody opened.
     *
     * @throws Exception
     */
    public function testMigratingWithoutACoordinatorIsRefused(): void
    {
        $policy = self::policy();
        $manager = $policy->createMigrationManager(self::model(), self::model("added"));
        $url = new URL("file:///nonexistent.xml");

        $this->expectException(InternalInconsistencyException::class);
        $policy->migrateStoreAtURL($url, $url, PersistentStoreType::xml, null, $manager);
    }

    /**
     * The defaults a freshly built policy carries. They are asserted because the class is a
     * configuration object a caller fills in: what it does NOT set is as much part of its shape
     * as what it does.
     */
    public function testAFreshPolicyDefaultsToTheSQLStoreAndNoModels(): void
    {
        $policy = new StoreMigrationPolicy();

        $this->assertSame(PersistentStoreType::sql, $policy->sourceType);
        $this->assertSame(PersistentStoreType::sql, $policy->destinationType);
        $this->assertNull($policy->sourceModel);
        $this->assertNull($policy->destinationModel);
        $this->assertNull($policy->mappingModel);
        $this->assertNull($policy->persistentStoreCoordinator);
    }
}
