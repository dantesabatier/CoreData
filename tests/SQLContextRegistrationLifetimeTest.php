<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use ReflectionProperty;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyValueObservance;
use Sabatier\Foundation\ObjectClass;
use WeakReference;

/**
 * @property string $code
 */
final class LifetimeRow extends ManagedObject
{
}

/**
 * Covers how long a context keeps the objects registered with it, and what reset() leaves behind.
 *
 * Regression guards:
 *  - with retainsRegisteredObjects off, a clean object nothing else holds is released, and reading
 *    its row again answers a new instance; an object with unsaved changes stays retained until they
 *    are processed. An attribute change used to be recorded by object ID alone, so an object
 *    released before the save was rebuilt from the store and the change was silently lost;
 *  - reset() unregisters every object and discards the changes not yet processed: the registration
 *    table, the reference observations and the context's own observers are all gone afterwards,
 *    whether the context retains its objects or not. Objects already released were left behind,
 *    with the observations of their object IDs.
 */
final class SQLContextRegistrationLifetimeTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $row = new EntityDescription();
        $row->name = "LifetimeRow";
        $row->managedObjectClassName = LifetimeRow::class;
        $row->properties = new ArrayClass([$code]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$row]);
        return $model;
    }

    /**
     * @throws Exception
     */
    private function seed(int $count): void
    {
        $context = $this->bootstrap(self::model());
        for ($i = 0; $i < $count; $i++) {
            $row = new LifetimeRow($context);
            $row->code = "R$i";
        }
        $context->save();
    }

    /**
     * @return Dictionary<mixed>
     */
    private static function privateTable(ManagedObjectContext $context, string $name): Dictionary
    {
        return new ReflectionProperty(ManagedObjectContext::class, $name)->getValue($context);
    }

    private static function observancesBy(ObjectClass $object, object $observer): int
    {
        /** @var list<KeyValueObservance> $observances */
        $observances = new ReflectionProperty(ObjectClass::class, "observances")->getValue($object);
        return new ArrayClass($observances)->filter(fn(KeyValueObservance $observance): bool => $observance->observer === $observer)->count;
    }

    /**
     * @throws Exception
     */
    public function testAnUnretainedCleanObjectIsReleasedAndItsRowAnswersANewInstance(): void
    {
        $this->seed(1);
        $context = $this->freshContext(self::model());
        $context->retainsRegisteredObjects = false;
        $row = $context->fetch(LifetimeRow::fetchRequest())->first ?? self::fail("the row was not fetched");
        $objectID = $row->objectID;
        $released = WeakReference::create($row);

        unset($row);
        gc_collect_cycles();

        $this->assertNull($released->get(), "nothing but the context held it, and the context holds it weakly");
        $this->assertNull($context->registeredObject($objectID));
        $this->assertInstanceOf(LifetimeRow::class, $context->object($objectID), "reading the row again answers a new instance");
    }

    /**
     * @throws Exception
     */
    public function testAnUnretainedObjectWithUnsavedChangesStaysRegistered(): void
    {
        $this->seed(1);
        $context = $this->freshContext(self::model());
        $context->retainsRegisteredObjects = false;
        $row = $context->fetch(LifetimeRow::fetchRequest())->first ?? self::fail("the row was not fetched");
        $row->code = "changed";
        $objectID = $row->objectID;
        $retained = WeakReference::create($row);

        unset($row);
        gc_collect_cycles();

        $this->assertNotNull($retained->get(), "an updated object is held by the context's updated objects");
        $this->assertSame("changed", $context->registeredObject($objectID)?->code);
    }

    /**
     * @throws Exception
     */
    public function testAnUnsavedChangeOfAnUnretainedObjectIsSaved(): void
    {
        $this->seed(1);
        $context = $this->freshContext(self::model());
        $context->retainsRegisteredObjects = false;
        $row = $context->fetch(LifetimeRow::fetchRequest())->first ?? self::fail("the row was not fetched");
        $row->code = "changed";

        unset($row);
        gc_collect_cycles();
        $context->save();

        $this->assertSame("changed", $this->freshContext(self::model())->fetch(LifetimeRow::fetchRequest())->first?->code);
    }

    /**
     * @throws Exception
     */
    public function testResetDiscardsUnsavedChanges(): void
    {
        $this->seed(1);
        $context = $this->freshContext(self::model());
        $row = $context->fetch(LifetimeRow::fetchRequest())->first ?? self::fail("the row was not fetched");
        $row->code = "changed";

        $context->reset();
        $context->save();

        $this->assertSame("R0", $this->freshContext(self::model())->fetch(LifetimeRow::fetchRequest())->first?->code);
    }

    /**
     * @throws Exception
     */
    public function testResetUnregistersEveryRetainedObject(): void
    {
        $this->seed(5);
        $context = $this->freshContext(self::model());
        $rows = $context->fetch(LifetimeRow::fetchRequest());

        $context->reset();

        $this->assertTrue($context->registeredObjects->isEmpty);
        $this->assertSame(0, self::privateTable($context, "byHashAssociationTable")->count);
        $this->assertSame(0, self::privateTable($context, "referenceObservations")->count);
        $this->assertSame(0, $rows->reduce(0, fn(int &$total, LifetimeRow $row): int => $total += self::observancesBy($row, $context)), "the context no longer observes the objects it let go");
    }

    /**
     * @throws Exception
     */
    public function testResetLeavesNothingBehindForReleasedObjects(): void
    {
        $this->seed(5);
        $context = $this->freshContext(self::model());
        $context->retainsRegisteredObjects = false;
        $context->fetch(LifetimeRow::fetchRequest());
        gc_collect_cycles();

        $context->reset();

        $this->assertSame(0, self::privateTable($context, "byHashAssociationTable")->count, "no entry for an object that was already released");
        $this->assertSame(0, self::privateTable($context, "referenceObservations")->count, "nor an observation of its object ID");
    }
}
