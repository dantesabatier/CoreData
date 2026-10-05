<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Set;

/**
 * @property string $name
 * @property Set<SQLSchedulePeriod>|null $periods
 */
final class SQLSchedule extends ManagedObject
{
}

/**
 * @property string $name
 * @property SQLSchedule|null $schedule
 */
class SQLSchedulePeriod extends ManagedObject
{
}

final class SQLScheduleBooking extends SQLSchedulePeriod
{
}

/**
 * A to-many whose destination is concrete holds only rows of that entity, even when a subentity shares the inverse.
 */
final class SQLConcreteDestinationReconciliationTest extends SQLMigrationTestCase
{
    /** SQLSchedule 1 <-> * SQLSchedulePeriod, with SQLScheduleBooking a subentity of the period. */
    private static function makeModel(): ManagedObjectModel
    {
        $scheduleName = new AttributeDescription();
        $scheduleName->name = "name";
        $scheduleName->type = AttributeType::string;

        $periods = new RelationshipDescription();
        $periods->name = "periods";
        $periods->lazyDestinationEntityName = "SQLSchedulePeriod";
        $periods->lazyInverseRelationshipName = "schedule";
        $periods->isToMany = true;
        $periods->isOptional = true;

        $schedule = new EntityDescription();
        $schedule->name = "SQLSchedule";
        $schedule->managedObjectClassName = SQLSchedule::class;
        $schedule->properties = new ArrayClass([$scheduleName, $periods]);

        $periodName = new AttributeDescription();
        $periodName->name = "name";
        $periodName->type = AttributeType::string;

        $scheduleRef = new RelationshipDescription();
        $scheduleRef->name = "schedule";
        $scheduleRef->lazyDestinationEntityName = "SQLSchedule";
        $scheduleRef->lazyInverseRelationshipName = "periods";
        $scheduleRef->isOptional = true;

        $period = new EntityDescription();
        $period->name = "SQLSchedulePeriod";
        $period->managedObjectClassName = SQLSchedulePeriod::class;
        $period->properties = new ArrayClass([$periodName, $scheduleRef]);

        $booking = new EntityDescription();
        $booking->name = "SQLScheduleBooking";
        $booking->managedObjectClassName = SQLScheduleBooking::class;
        $booking->superentity = $period;
        $booking->properties = new ArrayClass();

        $period->subentities = new ArrayClass([$booking]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$schedule, $period]);
        return $model;
    }

    /**
     * A schedule with two saved periods, read back through a fresh context that has not touched its periods.
     *
     * @throws Exception
     */
    private function scheduleWithUnreadPeriods(): SQLSchedule
    {
        $seed = $this->bootstrap(self::makeModel());
        $schedule = new SQLSchedule($seed);
        $schedule->name = "schedule";
        foreach (["morning", "afternoon"] as $name) {
            $period = new SQLSchedulePeriod($seed);
            $period->name = $name;
            $period->schedule = $schedule;
        }
        $seed->save();

        $context = $this->freshContext(self::makeModel());
        /** @var SQLSchedule|null $loaded */
        $loaded = $context->fetch(SQLSchedule::fetchRequest())->first;
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->hasFaultForRelationshipNamed("periods"), "precondition: the periods have not been read");
        return $loaded;
    }

    /** @throws Exception */
    public function testASubentitySharingTheInverseIsNotAddedToAConcreteDestination(): void
    {
        $schedule = $this->scheduleWithUnreadPeriods();
        $booking = new SQLScheduleBooking($schedule->managedObjectContext);
        $booking->name = "booking";

        $booking->schedule = $schedule;

        $this->assertSame(2, $schedule->periods?->count ?? -1, "the store answers with periods only, and so must the reconciliation");
        $this->assertFalse($schedule->periods?->containsElement($booking) ?? true, "the booking is not one of the periods");
    }

    /** @throws Exception */
    public function testAPendingMemberOfTheDestinationEntityIsStillAdded(): void
    {
        $schedule = $this->scheduleWithUnreadPeriods();
        $period = new SQLSchedulePeriod($schedule->managedObjectContext);
        $period->name = "evening";

        $period->schedule = $schedule;

        $this->assertSame(3, $schedule->periods?->count ?? -1, "a new period joins the two stored ones");
        $this->assertTrue($schedule->periods?->containsElement($period) ?? false);
    }
}
