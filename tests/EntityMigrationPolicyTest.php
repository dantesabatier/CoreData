<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use RuntimeException;
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
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SQLInPlaceMigrationManager;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;

/**
 * @property Set<InvoiceLine> $lines
 * @method void addLinesObject(InvoiceLine $object)
 * @method void removeLinesObject(InvoiceLine $object)
 * @method void addLines(Set<InvoiceLine> $objects)
 * @method void removeLines(Set<InvoiceLine> $objects)
 * @method Set<InvoiceLine> intersectLines(Set<InvoiceLine> $objects)
 * @method void setLines(Set<InvoiceLine> $objects)
 */
final class Invoice extends ManagedObject
{
}

final class InvoiceLine extends ManagedObject
{
}

/**
 * A policy that leaves every hook at its inherited behavior. Used to prove the base class carries
 * a whole migration on its own, which is what makes the five no-op hooks optional overrides.
 */
final class InheritedMigrationPolicy extends EntityMigrationPolicy
{
}

/**
 * A policy that derives a destination value the framework cannot infer — the reason
 * customEntityMappingType exists. It combines two source attributes into one destination
 * attribute, then defers to the inherited implementation for everything else.
 */
final class DerivingMigrationPolicy extends EntityMigrationPolicy
{
    #[Override]
    public function createDestinationInstances(ManagedObject $sourceInstance, EntityMapping $mapping, MigrationManager $manager): bool
    {
        if (!parent::createDestinationInstances($sourceInstance, $mapping, $manager)) {
            return false;
        }
        $destination = $manager->destinationInstances($mapping->name, new ArrayClass([$sourceInstance]))->first;
        $destination->label = $sourceInstance->series . "-" . $sourceInstance->folio;
        return true;
    }
}

/**
 * A policy that refuses the migration from its first hook, to check that an error from a policy
 * propagates through the migration manager.
 */
final class ThrowingMigrationPolicy extends EntityMigrationPolicy
{
    #[Override]
    public function begin(EntityMapping $mapping, MigrationManager $manager): bool
    {
        throw new RuntimeException("migration refused by policy");
    }
}

/**
 * Tests EntityMigrationPolicy — the class a caller subclasses to supply the destination values a
 * migration cannot derive on its own.
 *
 * Three other suites extend it, but all of them override the hooks to record or gate calls; none
 * exercises what the base class actually does. That is the gap here: the five hooks that return
 * true so a subclass can ignore them, and the two that carry real work —
 * createDestinationInstances, which applies the mapping's attribute expressions and associates
 * source with destination, and createRelationships, which reconnects the migrated graph.
 *
 * Run through SQLInPlaceMigrationManager against a real database, because the policy's behavior
 * depends on the manager's association bookkeeping and on performedInPlaceMigration, neither of
 * which a hand-built double would reproduce faithfully.
 */
final class EntityMigrationPolicyTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        return $attribute;
    }

    /**
     * A one-entity model, in the two shapes the migration moves between: the destination adds a
     * "label" the source does not have.
     *
     * @param list<AttributeDescription> $invoiceAttributes
     */
    private static function model(array $invoiceAttributes): ManagedObjectModel
    {
        $invoice = new EntityDescription();
        $invoice->name = "Invoice";
        $invoice->managedObjectClassName = Invoice::class;
        $invoice->properties = new ArrayClass($invoiceAttributes);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$invoice]);
        return $model;
    }

    private static function sourceModel(): ManagedObjectModel
    {
        return self::model([
            self::attribute("series", AttributeType::string),
            self::attribute("folio", AttributeType::integer32),
        ]);
    }

    private static function destinationModel(): ManagedObjectModel
    {
        return self::model([
            self::attribute("series", AttributeType::string),
            self::attribute("folio", AttributeType::integer32),
            self::attribute("label", AttributeType::string),
        ]);
    }

    /**
     * A related pair of entities, so the relationship pass has something to reconnect.
     *
     * @return ManagedObjectModel a model where Invoice has many InvoiceLines
     */
    private static function relatedModel(): ManagedObjectModel
    {
        $lines = new RelationshipDescription();
        $lines->name = "lines";
        $lines->lazyDestinationEntityName = "InvoiceLine";
        $lines->lazyInverseRelationshipName = "invoice";
        $lines->isToMany = true;

        $invoice = new EntityDescription();
        $invoice->name = "Invoice";
        $invoice->managedObjectClassName = Invoice::class;
        $invoice->properties = new ArrayClass([self::attribute("series", AttributeType::string), $lines]);

        $owner = new RelationshipDescription();
        $owner->name = "invoice";
        $owner->lazyDestinationEntityName = "Invoice";
        $owner->lazyInverseRelationshipName = "lines";
        $owner->maxCount = 1;

        $line = new EntityDescription();
        $line->name = "InvoiceLine";
        $line->managedObjectClassName = InvoiceLine::class;
        $line->properties = new ArrayClass([self::attribute("concept", AttributeType::string), $owner]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$invoice, $line]);
        return $model;
    }

    /**
     * @param class-string<EntityMigrationPolicy> $policyClassName
     * @param list<PropertyMapping> $attributeMappings
     * @param list<PropertyMapping> $relationshipMappings
     */
    private static function entityMapping(string $entityName, string $policyClassName, array $attributeMappings, array $relationshipMappings = []): EntityMapping
    {
        $mapping = new EntityMapping();
        $mapping->sourceEntityName = $entityName;
        $mapping->destinationEntityName = $entityName;
        $mapping->mappingType = EntityMappingType::customEntityMappingType;
        $mapping->entityMigrationPolicyClassName = $policyClassName;
        $mapping->attributeMappings = new ArrayClass($attributeMappings);
        $mapping->relationshipMappings = new ArrayClass($relationshipMappings);
        return $mapping;
    }

    /**
     * @param class-string<EntityMigrationPolicy> $policyClassName
     * @param list<PropertyMapping> $attributeMappings
     */
    private static function mappingModel(string $policyClassName, array $attributeMappings): MappingModel
    {
        $mappingModel = new MappingModel();
        $mappingModel->entityMappings = new ArrayClass([self::entityMapping("Invoice", $policyClassName, $attributeMappings)]);
        return $mappingModel;
    }

    /**
     * Seeds one invoice through the source model, then migrates the same store in place with the
     * given mapping model.
     *
     * @param class-string<EntityMigrationPolicy> $policyClassName
     * @param list<PropertyMapping> $attributeMappings
     */
    private function migrate(string $policyClassName, array $attributeMappings): void
    {
        $sourceModel = self::sourceModel();
        $context = $this->bootstrap($sourceModel);
        $invoice = new Invoice($context);
        $invoice->series = "A";
        $invoice->folio = 42;
        $context->save();

        $destinationModel = self::destinationModel();
        $manager = new SQLInPlaceMigrationManager($sourceModel, $destinationModel);
        $manager->migrateStore($this->storeURL, PersistentStoreType::sql, null, self::mappingModel($policyClassName, $attributeMappings), $this->storeURL, PersistentStoreType::sql, null);
    }

    // --- The inherited implementation ---

    /**
     * The base class carries a whole migration by itself: a policy that overrides nothing still
     * creates the destination instance and applies the mapping's attribute expressions. This is
     * what makes the five no-op hooks genuinely optional.
     */
    public function testAPolicyThatOverridesNothingStillMigratesTheData(): void
    {
        $this->migrate(InheritedMigrationPolicy::class, [
            new PropertyMapping("series", Expression::expressionWithFormat("\$source.series")),
            new PropertyMapping("folio", Expression::expressionWithFormat("\$source.folio")),
        ]);

        $this->assertSame(["A"], $this->columnValues("Invoice", "series"));
        $this->assertSame(["42"], $this->columnValues("Invoice", "folio"));
    }

    /**
     * An attribute with no mapping is left alone rather than being cleared: the policy only
     * assigns the keys the mapping names.
     */
    public function testAnUnmappedAttributeIsNotAssigned(): void
    {
        $this->migrate(InheritedMigrationPolicy::class, [
            new PropertyMapping("series", Expression::expressionWithFormat("\$source.series")),
        ]);

        $this->assertSame(["A"], $this->columnValues("Invoice", "series"));
        // "folio" had no mapping, so nothing was written to it during the pass. The in-place migration keeps the column, so the original value survives.
        $this->assertSame(["42"], $this->columnValues("Invoice", "folio"));
    }

    /**
     * A mapping whose expression names no source key at all still runs; the destination simply
     * receives whatever the expression evaluates to. A constant is the simplest such case, and it
     * is how a migration supplies a value the source cannot provide.
     */
    public function testAConstantExpressionSuppliesAValueTheSourceLacks(): void
    {
        $this->migrate(InheritedMigrationPolicy::class, [
            new PropertyMapping("series", Expression::expressionWithFormat("\$source.series")),
            new PropertyMapping("label", Expression::expressionForConstantValue("supplied by the mapping")),
        ]);

        $this->assertSame(["supplied by the mapping"], $this->columnValues("Invoice", "label"));
    }

    /**
     * The expression is evaluated against the source instance, so it can read any source
     * attribute — including one whose name differs from the destination key it feeds.
     */
    public function testAnExpressionCanFeedADifferentlyNamedDestinationKey(): void
    {
        $this->migrate(InheritedMigrationPolicy::class, [
            new PropertyMapping("label", Expression::expressionWithFormat("\$source.series")),
        ]);

        $this->assertSame(["A"], $this->columnValues("Invoice", "label"), "the source's series ended up in the destination's label");
    }

    // --- Overriding a hook ---

    /**
     * The point of a custom policy: deriving a destination value the framework cannot infer. The
     * subclass defers to the inherited implementation for the mapped attributes, then computes
     * one of its own from two source attributes.
     */
    public function testASubclassCanDeriveAValueFromSeveralSourceAttributes(): void
    {
        $this->migrate(DerivingMigrationPolicy::class, [
            new PropertyMapping("series", Expression::expressionWithFormat("\$source.series")),
            new PropertyMapping("folio", Expression::expressionWithFormat("\$source.folio")),
        ]);

        $this->assertSame(["A-42"], $this->columnValues("Invoice", "label"), "the policy combined series and folio");
        $this->assertSame(["A"], $this->columnValues("Invoice", "series"), "and the inherited implementation still ran");
    }

    /**
     * An exception from a hook aborts the migration instead of being treated as a success. This
     * follows the Swift behavior of the policy API: the Boolean return is retained from the
     * Objective-C contract, while failures are reported by throwing.
     */
    public function testAnExceptionFromAHookAbortsTheMigration(): void
    {
        $sourceModel = self::sourceModel();
        $context = $this->bootstrap($sourceModel);
        $invoice = new Invoice($context);
        $invoice->series = "A";
        $invoice->folio = 42;
        $context->save();

        $manager = new SQLInPlaceMigrationManager($sourceModel, self::destinationModel());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("migration refused by policy");
        $manager->migrateStore(
            $this->storeURL,
            PersistentStoreType::sql,
            null,
            self::mappingModel(ThrowingMigrationPolicy::class, [new PropertyMapping("series", Expression::expressionWithFormat("\$source.series"))]),
            $this->storeURL,
            PersistentStoreType::sql,
            null,
        );
    }

    // --- Relationships ---

    /**
     * The relationship pass reconnects the migrated graph: createRelationships looks up the
     * destination instances that correspond to the source's related objects, so a to-many
     * relationship survives the migration with its members intact.
     *
     * The two passes are separate for exactly this reason — the lines must already exist as
     * destination instances before the invoice can point at them.
     */
    public function testARelationshipIsReconnectedAcrossTheMigration(): void
    {
        $sourceModel = self::relatedModel();
        $context = $this->bootstrap($sourceModel);
        $invoice = new Invoice($context);
        $invoice->series = "A";
        $first = new InvoiceLine($context);
        $first->concept = "first line";
        $first->setValueForKey($invoice, "invoice");
        $second = new InvoiceLine($context);
        $second->concept = "second line";
        $second->setValueForKey($invoice, "invoice");
        $context->save();

        $destinationModel = self::relatedModel();
        $mappingModel = new MappingModel();
        $mappingModel->entityMappings = new ArrayClass([
            self::entityMapping("Invoice", InheritedMigrationPolicy::class, [
                new PropertyMapping("series", Expression::expressionWithFormat("\$source.series")),
            ], [
                new PropertyMapping("lines", Expression::expressionWithFormat("FUNCTION(\$manager, destinationInstances, %s, \$source)", new ArrayClass(["InvoiceLineToInvoiceLine"]))),
            ]),
            self::entityMapping("InvoiceLine", InheritedMigrationPolicy::class, [
                new PropertyMapping("concept", Expression::expressionWithFormat("\$source.concept")),
            ], [
                new PropertyMapping("invoice", Expression::expressionWithFormat("FUNCTION(\$manager, destinationInstances, %s, \$source)", new ArrayClass(["InvoiceToInvoice"]))),
            ]),
        ]);

        $manager = new SQLInPlaceMigrationManager($sourceModel, $destinationModel);
        $manager->migrateStore($this->storeURL, PersistentStoreType::sql, null, $mappingModel, $this->storeURL, PersistentStoreType::sql, null);

        $readContext = $this->freshContext(self::relatedModel());
        $migrated = $readContext->fetch(Invoice::fetchRequest())->first;

        $concepts = [];
        // Iterate the relationship directly: converting its faulting set to ArrayClass exposes stored ManagedObjectIDs instead of materialized InvoiceLine objects.
        foreach ($migrated->lines as $line) {
            $concepts[] = $line->concept;
        }
        sort($concepts);

        $this->assertSame(["first line", "second line"], $concepts, "both lines are still attached to the invoice");
    }

    /**
     * A mapping with no relationship mappings reports that it did no relationship work. The
     * inherited createRelationships returns false in that case, which the manager treats as
     * "nothing to do" rather than as a failure — an entity with no relationships migrates fine.
     */
    public function testAMappingWithoutRelationshipMappingsStillMigrates(): void
    {
        $this->migrate(InheritedMigrationPolicy::class, [
            new PropertyMapping("series", Expression::expressionWithFormat("\$source.series")),
        ]);

        $this->assertSame(["A"], $this->columnValues("Invoice", "series"), "the migration completed without any relationship mapping");
    }
}
