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
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Set;

/**
 * @property string $serial
 * @property string|null $nickname
 */
final class ValidationWidget extends ManagedObject
{
}

/**
 * @property string $name
 * @property Set<ValidationPart> $parts
 */
final class ValidationOwner extends ManagedObject
{
}

/**
 * @property string $code
 * @property ValidationOwner|null $owner
 */
final class ValidationPart extends ManagedObject
{
}

/**
 * Covers the keys an object validates that its own entity does not declare.
 *
 * A store adds columns of its own, and a hydrating store writes them back through the same
 * validation path an ordinary attribute takes. A foreign key is the one that reaches the last
 * branch: it is neither an entity property nor a PHP property, so validateValueForKey falls past
 * both lookups and asks the STORE's model, the only place that declares it.
 *
 * That branch is exercised here but cannot be pinned, and the reason is worth recording. It
 * answers true for every store column except ManagedObjectEntityNameKey, and the method's final
 * statement answers true as well — so replacing the whole block with false changes nothing
 * observable, measured. Its one distinguishing answer is unreachable: "entityName" is declared on
 * ManagedObject itself, so the hasProperty branch above returns first and the refusal never runs.
 *
 * Also covers updateFromUndoSnapshot, which the undo machinery uses to put an object back the
 * way it was. Its one branch worth pinning is the transient filter: a transient property is not
 * persisted, so restoring it from a snapshot that excludes them must leave it untouched rather
 * than blank it.
 */
final class ManagedObjectValidationKeysTest extends SQLMigrationTestCase
{
    /** "nickname" is transient, so it never reaches the store and an undo may be asked to leave it alone. */
    private static function model(): ManagedObjectModel
    {
        $serial = new AttributeDescription();
        $serial->name = "serial";
        $serial->type = AttributeType::string;

        $nickname = new AttributeDescription();
        $nickname->name = "nickname";
        $nickname->type = AttributeType::string;
        $nickname->isTransient = true;
        $nickname->isOptional = true;

        $widget = new EntityDescription();
        $widget->name = "ValidationWidget";
        $widget->managedObjectClassName = ValidationWidget::class;
        $widget->properties = new ArrayClass([$serial, $nickname]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget]);
        return $model;
    }

    /**
     * Owner -1----*- Part, so the store puts an "ownerID" foreign-key column on the Part table
     * that nothing on the object declares.
     */
    private static function relatedModel(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $parts = new RelationshipDescription();
        $parts->name = "parts";
        $parts->lazyDestinationEntityName = "ValidationPart";
        $parts->lazyInverseRelationshipName = "owner";
        $parts->isToMany = true;

        $owner = new EntityDescription();
        $owner->name = "ValidationOwner";
        $owner->managedObjectClassName = ValidationOwner::class;
        $owner->properties = new ArrayClass([$name, $parts]);

        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $ownerRelationship = new RelationshipDescription();
        $ownerRelationship->name = "owner";
        $ownerRelationship->lazyDestinationEntityName = "ValidationOwner";
        $ownerRelationship->lazyInverseRelationshipName = "parts";
        $ownerRelationship->maxCount = 1;

        $part = new EntityDescription();
        $part->name = "ValidationPart";
        $part->managedObjectClassName = ValidationPart::class;
        $part->properties = new ArrayClass([$code, $ownerRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$owner, $part]);
        return $model;
    }

    /**
     * A foreign-key column validates even though nothing on the object declares it: it is not an
     * entity property and not a PHP property either, so the lookup falls all the way through to
     * the store's own model, which is the only place that knows the column exists.
     *
     * The key has to be a foreign key rather than one of the store's other columns. Measured:
     * "version" and "entityName" are declared on ManagedObject itself, so they are answered by
     * the hasProperty branch above and never reach the store lookup at all.
     *
     * @throws Exception
     */
    public function testAForeignKeyColumnAbsentFromTheEntityStillValidates(): void
    {
        $context = $this->bootstrap(self::relatedModel());
        $part = new ValidationPart($context);
        $part->code = "P-1";
        $context->save();

        $value = 1;
        $this->assertFalse($part->hasProperty("ownerID"), "precondition: the foreign-key column is not a property of the object");
        $this->assertTrue($part->validateValueForKey($value, "ownerID"), "the store's foreign-key column validates, because the store's model declares it");
    }

    /**
     * A key no one declares — not the entity, not the object, not the store — validates as an
     * ordinary unknown key rather than being refused here.
     *
     * @throws Exception
     */
    public function testAKeyNoModelDeclaresStillValidates(): void
    {
        $context = $this->bootstrap(self::relatedModel());
        $part = new ValidationPart($context);
        $part->code = "P-1";
        $context->save();

        $value = "anything";
        $this->assertTrue($part->validateValueForKey($value, "notAColumnAnywhere"), "an unknown key is not rejected by this path");
    }

    /**
     * An undo snapshot that excludes transients leaves the transient property as it stands: it
     * was never persisted, so the snapshot has nothing to say about it.
     *
     * @throws Exception
     */
    public function testAnUndoSnapshotWithoutTransientsLeavesThemUntouched(): void
    {
        $context = $this->bootstrap(self::model());
        $widget = new ValidationWidget($context);
        $widget->serial = "S-1";
        $widget->nickname = "keep me";
        $context->save();

        $widget->updateFromUndoSnapshot(new Dictionary(["serial" => "S-restored", "nickname" => "discard me"]), includingTransients: false);

        $this->assertSame("S-restored", $widget->serial, "the persisted attribute is restored from the snapshot");
        $this->assertSame("keep me", (string)$widget->nickname, "the transient one is left as it stands, because the snapshot excluded transients");
    }

    /**
     * And including them restores the transient too, which is what makes the previous test about
     * the filter rather than about transients being unwritable.
     *
     * @throws Exception
     */
    public function testAnUndoSnapshotIncludingTransientsRestoresThem(): void
    {
        $context = $this->bootstrap(self::model());
        $widget = new ValidationWidget($context);
        $widget->serial = "S-1";
        $widget->nickname = "original";
        $context->save();

        $widget->updateFromUndoSnapshot(new Dictionary(["serial" => "S-restored", "nickname" => "restored"]), includingTransients: true);

        $this->assertSame("restored", (string)$widget->nickname, "the transient is restored when the snapshot includes them");
    }
}
