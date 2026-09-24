<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\InternalInconsistencyException;

/**
 * @property string $code
 * @property int<1, 5> $priority
 */
final class SQLDiscardableTicket extends ManagedObject
{
}

/**
 * Deleting an object the store holds no row for forgets it rather than scheduling a delete. The same sequences as the XML store's regression tests in ManagedObjectContextTest, against SQLCore.
 *
 * Reuses SQLMigrationTestCase for its database lifecycle only; no migration is exercised.
 */
final class SQLDeleteUnsavedObjectTest extends SQLMigrationTestCase
{
    private static function makeModel(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $priority = new AttributeDescription();
        $priority->name = "priority";
        $priority->type = AttributeType::integer32;
        $priority->defaultValue = 3;
        $priority->minValue = 1;
        $priority->maxValue = 5;

        $entity = new EntityDescription();
        $entity->name = "SQLDiscardableTicket";
        $entity->managedObjectClassName = SQLDiscardableTicket::class;
        $entity->properties = new ArrayClass([$code, $priority]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    private function persistedCodes(): array
    {
        return $this->freshContext(self::makeModel())->fetch(SQLDiscardableTicket::fetchRequest())->map(fn(SQLDiscardableTicket $ticket): string => $ticket->code)->array;
    }

    /** @throws Exception */
    private function saveAKeptTicket(ManagedObjectContext $context): void
    {
        $kept = new SQLDiscardableTicket($context);
        $kept->code = "KEPT";
        $this->assertTrue($context->save(), "the next save reports success");
        $this->assertSame(["KEPT"], $this->persistedCodes(), "only the kept object reached the store");
    }

    /** @throws Exception */
    public function testDeletingAnUnsavedObjectForgetsIt(): void
    {
        $context = $this->bootstrap(self::makeModel());
        $discarded = new SQLDiscardableTicket($context);
        $discarded->code = "DISCARDED";
        $context->processPendingChanges();

        $context->delete($discarded);
        $this->assertFalse($context->deletedObjects->containsElement($discarded), "there is no row to delete");
        $this->saveAKeptTicket($context);
    }

    /** @throws Exception */
    public function testDeletingAnObjectAfterASaveRejectedByValidationForgetsIt(): void
    {
        $context = $this->bootstrap(self::makeModel());
        $discarded = new SQLDiscardableTicket($context);
        $discarded->code = "DISCARDED";
        /** @psalm-suppress InvalidPropertyAssignmentValue the out-of-range value is what makes validation reject the save */
        $discarded->priority = 99;
        try {
            $context->save();
            $this->fail("the out-of-range priority must reject the save");
        } catch (InternalInconsistencyException) {
        }
        $this->assertFalse($discarded->objectID->isTemporaryID, "the rejected save left a permanent ID behind");

        $context->delete($discarded);
        $this->assertFalse($context->deletedObjects->containsElement($discarded), "a permanent ID is not a row");
        $this->saveAKeptTicket($context);
    }

    /** @throws Exception */
    public function testDeletingASavedObjectStillDeletesItsRow(): void
    {
        $context = $this->bootstrap(self::makeModel());
        $saved = new SQLDiscardableTicket($context);
        $saved->code = "SAVED";
        $this->assertTrue($context->save(), "the first save reports success");

        $context->delete($saved);
        $this->assertTrue($context->deletedObjects->containsElement($saved), "the saved object is scheduled for deletion");
        $this->saveAKeptTicket($context);
    }
}
