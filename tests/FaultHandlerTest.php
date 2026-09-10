<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FaultingSet;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\ManagedObjectFaultingStateStable;
use const Sabatier\CoreData\ManagedObjectFaultingStateUnstable;

/**
 * @property string $name
 * @property Set<Widget> $parts
 * @method void addPartsObject(Widget $object)
 * @method void removePartsObject(Widget $object)
 * @method void addParts(Set<Widget> $objects)
 * @method void removeParts(Set<Widget> $objects)
 * @method Set<Widget> intersectParts(Set<Widget> $objects)
 * @method void setParts(Set<Widget> $objects)
 */
final class Sprocket extends ManagedObject
{
}

/**
 * @property Sprocket $gadget
 * @property string $label
 * @property int $qty
 */
final class Widget extends ManagedObject
{
}

/**
 * Tests for FaultHandler — the state machine behind lazy loading. fulfillFault() hydrates a fault
 * from the store's snapshot node; turnObjectIntoFault() restores committed values, refaults
 * relationships, and evicts the row cache. Both toggle KVO/change-notification suppression flags
 * and are exercised on every lazy access, but had no direct coverage.
 *
 * A real XML-backed stack is used: an object fetched through a fresh context is materialized, and
 * the two transitions are driven directly on its faultHandler so the before/after state
 * (isFault, faultingState, hydrated values, refaulted to-many relationship, row-cache eviction)
 * can be observed. The fetch-then-transition flow was verified against the live handler first.
 *
 * Model: Sprocket.parts (to-many) <-> Widget.gadget (to-one), so turnObjectIntoFault's relationship
 * refaulting can be checked on a populated FaultingSet.
 */
final class FaultHandlerTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $gadgetName = new AttributeDescription();
        $gadgetName->name = "name";
        $gadgetName->type = AttributeType::string;

        $parts = new RelationshipDescription();
        $parts->name = "parts";
        $parts->lazyDestinationEntityName = "Widget";
        $parts->lazyInverseRelationshipName = "gadget";
        $parts->isToMany = true;

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $qty = new AttributeDescription();
        $qty->name = "qty";
        $qty->type = AttributeType::integer32;

        $gadget = new RelationshipDescription();
        $gadget->name = "gadget";
        $gadget->lazyDestinationEntityName = "Sprocket";
        $gadget->lazyInverseRelationshipName = "parts";
        $gadget->maxCount = 1;

        $gadgetEntity = new EntityDescription();
        $gadgetEntity->name = "Sprocket";
        $gadgetEntity->managedObjectClassName = Sprocket::class;
        $gadgetEntity->properties = new ArrayClass([$gadgetName, $parts]);

        $widgetEntity = new EntityDescription();
        $widgetEntity->name = "Widget";
        $widgetEntity->managedObjectClassName = Widget::class;
        $widgetEntity->properties = new ArrayClass([$label, $qty, $gadget]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$gadgetEntity, $widgetEntity]);
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

    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-faulthandler-test-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    #[Override]
    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    private function seedWidget(string $label, int $qty): void
    {
        $context = $this->context();
        $widget = new Widget($context);
        $widget->label = $label;
        $widget->qty = $qty;
        $context->save();
    }

    public function testTurnObjectIntoFaultReflagsAMaterializedObject(): void
    {
        $this->seedWidget("hammer", 3);
        $context = $this->context();
        $widget = $context->fetch(Widget::fetchRequest())->first;
        $this->assertFalse($widget->isFault, "precondition: a fetched object is materialized");
        $this->assertSame(ManagedObjectFaultingStateStable, $widget->faultingState);

        $widget->faultHandler->turnObjectIntoFault($widget);

        $this->assertTrue($widget->isFault, "turnObjectIntoFault re-flags the object as a fault");
        $this->assertSame(ManagedObjectFaultingStateUnstable, $widget->faultingState, "faulting state becomes unstable");
    }

    public function testTurnObjectIntoFaultIsIdempotentOnAnExistingFault(): void
    {
        $this->seedWidget("wrench", 1);
        $context = $this->context();
        $widget = $context->fetch(Widget::fetchRequest())->first;
        $widget->faultHandler->turnObjectIntoFault($widget);
        $this->assertTrue($widget->isFault, "precondition: already a fault");

        // Second call must be a no-op (the guard `if ($object->isFault) return;`).
        $widget->faultHandler->turnObjectIntoFault($widget);
        $this->assertTrue($widget->isFault, "turning an existing fault into a fault is a harmless no-op");
    }

    public function testTurnObjectIntoFaultEvictsTheRowCacheSnapshot(): void
    {
        $this->seedWidget("drill", 7);
        $context = $this->context();
        $store = $context->persistentStoreCoordinator->persistentStores->first;
        $widget = $context->fetch(Widget::fetchRequest())->first;
        $objectID = $widget->objectID;

        // The atomic (XML) store keeps its own node cache and does not populate the row cache on
        // fault, so seed the row cache directly to prove turnObjectIntoFault's eviction step
        // (FaultHandler line: rowCache?->deleteSnapshot($object->objectID)).
        $store->rowCache->setSnapshot(new Dictionary(["label" => "drill"]), $objectID);
        $this->assertNotNull($store->rowCache->snapshot($objectID), "precondition: a snapshot is in the row cache");

        $widget->faultHandler->turnObjectIntoFault($widget);

        $this->assertNull($store->rowCache->snapshot($objectID), "turnObjectIntoFault evicts the row-cache snapshot");
    }

    public function testFulfillFaultRehydratesValuesFromTheStore(): void
    {
        $this->seedWidget("saw", 9);
        $context = $this->context();
        $widget = $context->fetch(Widget::fetchRequest())->first;
        $widget->faultHandler->turnObjectIntoFault($widget);
        $this->assertTrue($widget->isFault, "precondition: object is a fault");

        $widget->faultHandler->fulfillFault($widget);

        $this->assertFalse($widget->isFault, "fulfillFault materializes the object");
        $this->assertSame("saw", (string)$widget->label, "attribute values are hydrated from the store");
        $this->assertSame(9, $widget->qty, "typed attribute is hydrated with its type");
    }

    public function testFulfillFaultIsANoOpOnAMaterializedObject(): void
    {
        $this->seedWidget("plane", 2);
        $context = $this->context();
        $widget = $context->fetch(Widget::fetchRequest())->first;
        $this->assertFalse($widget->isFault, "precondition: already materialized");

        // The guard `if (!$object->isFault) return;` — a second fulfill must not disturb it.
        $widget->faultHandler->fulfillFault($widget);
        $this->assertFalse($widget->isFault);
        $this->assertSame("plane", (string)$widget->label, "values are unchanged");
    }

    public function testTurnObjectIntoFaultRefaultsAToManyRelationship(): void
    {
        // Seed a gadget with two parts, so the gadget's "parts" is a populated FaultingSet.
        $context = $this->context();
        $gadget = new Sprocket($context);
        $gadget->name = "kit";
        foreach (["a", "b"] as $label) {
            $part = new Widget($context);
            $part->label = $label;
            $part->setValueForKey($gadget, "gadget");
        }
        $context->save();

        $readContext = $this->context();
        $reloaded = $readContext->fetch(Sprocket::fetchRequest())->first;
        // Accessing the relationship through the public getter fires the fault and materializes
        // the FaultingSet; primitiveValueForKey is null until then.
        /** @var FaultingSet $parts */
        $parts = $reloaded->valueForKey("parts");
        $this->assertInstanceOf(FaultingSet::class, $parts);
        $this->assertSame(2, $parts->count, "precondition: the to-many set is materialized with both parts");
        $this->assertFalse($parts->isFault, "precondition: the set is not a fault once materialized");

        $reloaded->faultHandler->turnObjectIntoFault($reloaded);

        $this->assertTrue($parts->isFault, "the to-many relationship set is turned back into a fault");
        $this->assertSame(0, $parts->count, "refaulting the relationship drops its materialized members");
    }
}
