<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchIndexDescription;
use Sabatier\CoreData\FetchIndexElementDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MergePolicy;
use Sabatier\CoreData\PersistentStore;
use Sabatier\Foundation\ArrayClass;

/**
 * @property string $code
 * @property string $label
 */
final class RewriteRow extends ManagedObject
{
}

/**
 * Covers the lookup tables keyed by an object ID's reference when that reference is rewritten in
 * place.
 *
 * Two paths repoint a ManagedObjectID at an existing row by assigning its referenceObject:
 * ManagedObject::validateValueForKey() when "objectID" receives an int or a string, and
 * SQLSaveChangesRequestContext::resolveUpsertConflicts() when an inserted object collides with an
 * existing row on a unique index. The rewrite is in place on purpose — every holder of that ID
 * object is reconciled at once — and these tests do not question it.
 *
 * What they guard is the two tables that key the ID by its reference as a string, computed before
 * the rewrite: ManagedObjectContext's registration table, keyed by the ID's URI, and
 * PersistentStore's per-entity ID cache, keyed by the reference itself. Left keyed by the old
 * reference, the context stops finding the object under its new ID, keeps it under the old one
 * after a reset, and the store hands out the reconciled ID for the reference it no longer carries —
 * so a different row that later receives that reference is read back as the adopted one.
 */
final class SQLObjectIDReferenceRewriteTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;
        $code->isOptional = false;

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $element = new FetchIndexElementDescription($code);
        $element->isUnique = true;

        $row = new EntityDescription();
        $row->name = "RewriteRow";
        $row->managedObjectClassName = RewriteRow::class;
        $row->properties = new ArrayClass([$code, $label]);
        $row->indexes = new ArrayClass([new FetchIndexDescription("rewrite_code", new ArrayClass([$element]))]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$row]);
        return $model;
    }

    /**
     * @throws Exception
     */
    private function insert(ManagedObjectContext $context, string $code, string $label): RewriteRow
    {
        $row = new RewriteRow($context);
        $row->code = $code;
        $row->label = $label;
        $context->save();
        return $row;
    }

    /**
     * @throws Exception
     */
    private function collide(ManagedObjectContext $context, string $code): RewriteRow
    {
        $context->mergePolicy = MergePolicy::overwrite();
        return $this->insert($context, $code, "duplicate");
    }

    private static function store(ManagedObjectContext $context): PersistentStore
    {
        return $context->persistentStoreCoordinator?->persistentStores->first ?? self::fail("the context has no store");
    }

    /**
     * After the collision the inserted object identifies the surviving row, and the context must
     * answer it — not a second instance of that row — when asked by that ID.
     *
     * @throws Exception
     */
    public function testACollidingInsertIsTheObjectRegisteredForTheRowItAdopted(): void
    {
        $this->insert($this->bootstrap(self::model()), "A1", "original");
        $context = $this->freshContext(self::model());
        $duplicate = $this->collide($context, "A1");

        $this->assertSame($duplicate, $context->registeredObject($duplicate->objectID));
        $this->assertSame($duplicate, $context->object(self::store($context)->objectID($duplicate->entity, $duplicate->objectID->referenceObject)));
    }

    /**
     * The reference the colliding insert was given and then gave up is free in the database, so
     * another stack may store a different row under it. Reading that row back through the
     * colliding context must yield that row, not the one the collision adopted.
     *
     * @throws Exception
     */
    public function testAReferenceGivenUpByACollisionDoesNotAliasTheAdoptedRow(): void
    {
        $this->insert($this->bootstrap(self::model()), "B1", "original");
        $context = $this->freshContext(self::model());
        $this->collide($context, "B1");
        $this->insert($this->freshContext(self::model()), "B2", "other");

        $codes = $context->fetch(RewriteRow::fetchRequest())->map(fn(RewriteRow $row): string => $row->code)->sort();

        $this->assertSame(["B1", "B2"], $codes->array);
    }

    /**
     * A reset unregisters every object, the colliding insert included.
     *
     * @throws Exception
     */
    public function testAResetForgetsACollidingInsert(): void
    {
        $this->insert($this->bootstrap(self::model()), "C1", "original");
        $context = $this->freshContext(self::model());
        $this->collide($context, "C1");

        $context->reset();

        $this->assertTrue($context->registeredObjects->isEmpty);
    }

    /**
     * An object whose permanent ID is repointed through key-value coding stays registered under
     * the ID it now carries.
     *
     * @throws Exception
     */
    public function testAnObjectIDAssignedThroughKeyValueCodingKeepsTheObjectRegistered(): void
    {
        $existing = $this->insert($this->bootstrap(self::model()), "D1", "original");
        $context = $this->freshContext(self::model());
        $row = new RewriteRow($context);
        $row->code = "D1";
        $row->setValueForKey($existing->objectID->referenceObject, "objectID");

        $this->assertSame($row, $context->registeredObject($row->objectID));
    }

    /**
     * The store answers an ID for the reference it was asked for, never the ID that was
     * repointed away from it.
     *
     * @throws Exception
     */
    public function testAReferenceReplacedThroughKeyValueCodingIsNotAnsweredWithTheRepointedID(): void
    {
        $existing = $this->insert($this->bootstrap(self::model()), "E1", "original");
        $context = $this->freshContext(self::model());
        $row = new RewriteRow($context);
        $row->code = "E1";
        $given = $row->objectID->referenceObject;
        $row->setValueForKey($existing->objectID->referenceObject, "objectID");

        $this->assertSame((string)$given, (string)self::store($context)->objectID($row->entity, $given)->referenceObject);
    }

    /**
     * An object whose temporary ID is repointed through key-value coding is unregistered by a
     * reset like any other.
     *
     * @throws Exception
     */
    public function testAResetForgetsAnObjectWhoseTemporaryIDWasRepointed(): void
    {
        $existing = $this->insert($this->bootstrap(self::model()), "F1", "original");
        $context = $this->freshContext(self::model());
        $row = new RewriteRow($context);
        $row->setValueForKey($existing->objectID->referenceObject, "objectID");
        $row->code = "F1";

        $context->reset();

        $this->assertTrue($context->registeredObjects->isEmpty);
    }
}
