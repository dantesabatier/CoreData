<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\EntityMapping;
use Sabatier\CoreData\EntityMappingType;
use Sabatier\CoreData\EntityMigrationPolicy;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MappingModel;
use Sabatier\CoreData\MigrationManager;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\PropertyMapping;
use Sabatier\CoreData\SQLInPlaceMigrationManager;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;

final class PolicyLedger extends ManagedObject
{
}

final class PolicyVoucher extends ManagedObject
{
}

/**
 * Records which entity mapping each hook was invoked for, so a test can tell whether a pass used the
 * policy belonging to the mapping it was processing.
 */
abstract class RecordingMigrationPolicy extends EntityMigrationPolicy
{
    /** @var array<string, list<string>> */
    public static array $calls = [];

    protected function record(string $hook, EntityMapping $mapping): void
    {
        static::$calls[static::class . "." . $hook][] = $mapping->name;
    }

    #[\Override]
    public function begin(EntityMapping $mapping, MigrationManager $manager): bool
    {
        $this->record("begin", $mapping);
        return parent::begin($mapping, $manager);
    }

    #[\Override]
    public function createRelationships(ManagedObject $instance, EntityMapping $mapping, MigrationManager $manager): bool
    {
        $this->record("createRelationships", $mapping);
        return parent::createRelationships($instance, $mapping, $manager);
    }

    #[\Override]
    public function performCustomValidation(EntityMapping $mapping, MigrationManager $manager): bool
    {
        $this->record("performCustomValidation", $mapping);
        return parent::performCustomValidation($mapping, $manager);
    }

    #[\Override]
    public function end(EntityMapping $mapping, MigrationManager $manager): bool
    {
        $this->record("end", $mapping);
        return parent::end($mapping, $manager);
    }
}

final class LedgerMigrationPolicy extends RecordingMigrationPolicy
{
}

final class VoucherMigrationPolicy extends RecordingMigrationPolicy
{
}

/**
 * A migration runs its three passes over every entity mapping, so the manager holds one policy per
 * mapping. Passes 2 and 3 have to recover the policy that belongs to the mapping they are
 * processing: with the passes iterating outside the mappings, a single stored policy is whichever
 * one pass 1 created last, and every later pass reports it against the wrong mapping.
 */
final class MigrationManagerPolicyPerMappingTest extends SQLMigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingMigrationPolicy::$calls = [];
    }

    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        return $attribute;
    }

    /**
     * @param list<AttributeDescription> $ledgerAttributes
     * @param list<AttributeDescription> $voucherAttributes
     */
    private static function model(array $ledgerAttributes, array $voucherAttributes): ManagedObjectModel
    {
        $ledger = new EntityDescription();
        $ledger->name = "PolicyLedger";
        $ledger->managedObjectClassName = PolicyLedger::class;
        $ledger->properties = new ArrayClass($ledgerAttributes);

        $voucher = new EntityDescription();
        $voucher->name = "PolicyVoucher";
        $voucher->managedObjectClassName = PolicyVoucher::class;
        $voucher->properties = new ArrayClass($voucherAttributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$ledger, $voucher]);
        return $model;
    }

    private static function sourceModel(): ManagedObjectModel
    {
        return self::model([self::attribute("reference", AttributeType::string)], [self::attribute("code", AttributeType::string)]);
    }

    private static function destinationModel(): ManagedObjectModel
    {
        return self::model(
            [self::attribute("reference", AttributeType::string), self::attribute("total", AttributeType::integer32)],
            [self::attribute("code", AttributeType::string), self::attribute("amount", AttributeType::integer32)],
        );
    }

    /**
     * @param class-string<EntityMigrationPolicy> $policyClassName
     * @param list<PropertyMapping> $attributeMappings
     */
    private static function entityMapping(string $entityName, string $policyClassName, array $attributeMappings): EntityMapping
    {
        $mapping = new EntityMapping();
        $mapping->sourceEntityName = $entityName;
        $mapping->destinationEntityName = $entityName;
        $mapping->mappingType = EntityMappingType::customEntityMappingType;
        $mapping->entityMigrationPolicyClassName = $policyClassName;
        $mapping->attributeMappings = new ArrayClass($attributeMappings);
        $mapping->relationshipMappings = new ArrayClass();
        return $mapping;
    }

    private static function mappingModel(): MappingModel
    {
        $mappingModel = new MappingModel();
        $mappingModel->entityMappings = new ArrayClass([
            self::entityMapping("PolicyLedger", LedgerMigrationPolicy::class, [
                new PropertyMapping("reference", Expression::expressionWithFormat("\$source.reference")),
            ]),
            self::entityMapping("PolicyVoucher", VoucherMigrationPolicy::class, [
                new PropertyMapping("code", Expression::expressionWithFormat("\$source.code")),
            ]),
        ]);
        return $mappingModel;
    }

    private function migrate(): void
    {
        $sourceModel = self::sourceModel();
        $context = $this->bootstrap($sourceModel);
        $ledger = new PolicyLedger($context);
        $ledger->reference = "LDG-1";
        $voucher = new PolicyVoucher($context);
        $voucher->code = "VCH-1";
        $context->save();

        $destinationModel = self::destinationModel();
        $manager = new SQLInPlaceMigrationManager($sourceModel, $destinationModel);
        $manager->migrateStore($this->storeURL, PersistentStoreType::sql, null, self::mappingModel(), $this->storeURL, PersistentStoreType::sql, null);
    }

    /**
     * @return list<string>
     */
    private static function calls(string $policyClassName, string $hook): array
    {
        return RecordingMigrationPolicy::$calls[$policyClassName . "." . $hook] ?? [];
    }

    public function testEachPassUsesThePolicyOfTheMappingItIsProcessing(): void
    {
        $this->migrate();

        $this->assertSame(["PolicyLedgerToPolicyLedger"], self::calls(LedgerMigrationPolicy::class, "begin"), "pass 1 must run the ledger policy for the ledger mapping");
        $this->assertSame(["PolicyVoucherToPolicyVoucher"], self::calls(VoucherMigrationPolicy::class, "begin"), "pass 1 must run the voucher policy for the voucher mapping");
        $this->assertSame(["PolicyLedgerToPolicyLedger"], self::calls(LedgerMigrationPolicy::class, "performCustomValidation"), "pass 3 must run the ledger policy, and only for its own mapping");
        $this->assertSame(["PolicyVoucherToPolicyVoucher"], self::calls(VoucherMigrationPolicy::class, "performCustomValidation"), "pass 3 must run the voucher policy, and only for its own mapping");
        $this->assertSame(["PolicyLedgerToPolicyLedger"], self::calls(LedgerMigrationPolicy::class, "end"), "pass 3 must end the ledger policy for its own mapping");
        $this->assertSame(["PolicyVoucherToPolicyVoucher"], self::calls(VoucherMigrationPolicy::class, "end"), "pass 3 must end the voucher policy for its own mapping");
    }
}
