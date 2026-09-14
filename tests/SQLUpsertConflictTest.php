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
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Set;

/**
 * @property string $code
 * @property string $label
 * @property Set<UpsertTag> $tags
 * @method void addTagsObject(UpsertTag $object)
 * @method void removeTagsObject(UpsertTag $object)
 * @method void addTags(Set<UpsertTag> $objects)
 * @method void removeTags(Set<UpsertTag> $objects)
 * @method Set<UpsertTag> intersectTags(Set<UpsertTag> $objects)
 * @method void setTags(Set<UpsertTag> $objects)
 */
final class UpsertRow extends ManagedObject
{
}

/**
 * @property string $title
 * @property UpsertRow $row
 */
final class UpsertTag extends ManagedObject
{
}

/**
 * @property string $name
 */
final class PlainRow extends ManagedObject
{
}

/**
 * Covers SQLSaveChangesRequestContext::resolveUpsertConflicts, which ran on every save and was
 * entirely untested — the class sat at 46.2%, the worst ratio of any request context.
 *
 * What it is meant to do: after a save inserts rows, re-fetch the values of every UNIQUE index
 * the inserted objects touched and, where a row with that value already existed, repoint the
 * inserted object's objectID at the existing row's reference. The insert was an upsert at the
 * SQL level, so the object the caller is holding has to end up identifying the row that
 * survived — otherwise it points at a reference the database never assigned.
 *
 * For a long time it appeared to do nothing: the assignment never fired, and the fetch for
 * existing rows returned the very object just inserted. The cause was upstream, in the generated
 * INSERT. prepareInsertStatement built "ON DUPLICATE KEY UPDATE" from every column it wrote, and
 * that set is seeded with the primary key — so a collision did not merely overwrite the existing
 * row's values, it RENUMBERED that row to the colliding object's reference. The resolution then
 * looked for a row carrying some other reference, found one already carrying its own, and
 * correctly concluded there was nothing to repoint. The defect masked itself.
 *
 * The renumbering was silent referential data loss: every foreign key still pointing at the old
 * primary key was orphaned, which testACollisionDoesNotOrphanRowsPointingAtTheSurvivingRow pins.
 * Excluding the primary key from the update clause fixes both halves at once — the surviving row
 * keeps its identity, and resolveUpsertConflicts becomes effective, repointing the colliding
 * object at the row that survived.
 *
 * Asserted through the model, never raw SQL: what matters is what a consumer sees afterwards.
 */
final class SQLUpsertConflictTest extends SQLMigrationTestCase
{
    /** An entity whose "code" carries a unique index, plus one with no index at all. */
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

        $tags = new RelationshipDescription();
        $tags->name = "tags";
        $tags->lazyDestinationEntityName = "UpsertTag";
        $tags->lazyInverseRelationshipName = "row";
        $tags->isToMany = true;

        $row = new EntityDescription();
        $row->name = "UpsertRow";
        $row->managedObjectClassName = UpsertRow::class;
        $row->properties = new ArrayClass([$code, $label, $tags]);
        $row->indexes = new ArrayClass([new FetchIndexDescription("upsert_code", new ArrayClass([$element]))]);

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $owner = new RelationshipDescription();
        $owner->name = "row";
        $owner->lazyDestinationEntityName = "UpsertRow";
        $owner->lazyInverseRelationshipName = "tags";

        $tag = new EntityDescription();
        $tag->name = "UpsertTag";
        $tag->managedObjectClassName = UpsertTag::class;
        $tag->properties = new ArrayClass([$title, $owner]);

        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $plain = new EntityDescription();
        $plain->name = "PlainRow";
        $plain->managedObjectClassName = PlainRow::class;
        $plain->properties = new ArrayClass([$name]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$row, $tag, $plain]);
        return $model;
    }

    /**
     * A stack whose merge policy lets a uniqueness collision reach the store.
     *
     * The default policy is MergePolicy::error(), and it fires first: ManagedObjectContext runs
     * ConflictDetectionService before handing the save to the store, so a colliding insert
     * raises in the context and resolveUpsertConflicts is never reached. Reaching the store's
     * own resolution therefore requires a policy that resolves rather than refuses — which is
     * itself the contract worth knowing, and is pinned by
     * testTheDefaultPolicyRefusesACollisionBeforeTheStoreSeesIt.
     *
     * @throws Exception
     */
    private function resolvingContext(): ManagedObjectContext
    {
        $context = $this->freshContext(self::model());
        $context->mergePolicy = MergePolicy::overwrite();
        return $context;
    }

    /**
     * Inserts a row with the given code through its own stack, and returns the reference the
     * store ended up assigning it.
     *
     * @throws Exception
     */
    private function insert(ManagedObjectContext $context, string $code, string $label): UpsertRow
    {
        $row = new UpsertRow($context);
        $row->code = $code;
        $row->label = $label;
        $context->save();
        return $row;
    }

    /**
     * The ordinary case: a unique value that does not collide keeps the reference the store
     * assigned it, and the row is really there.
     *
     * @throws Exception
     */
    public function testANonCollidingInsertKeepsItsOwnReference(): void
    {
        $context = $this->bootstrap(self::model());

        $first = $this->insert($context, "A1", "first");
        $second = $this->insert($context, "A2", "second");

        $this->assertNotSame(
            (string)$first->objectID->referenceObject,
            (string)$second->objectID->referenceObject,
            "two distinct codes are two distinct rows",
        );
        $this->assertSame(2, $this->freshContext(self::model())->fetch(UpsertRow::fetchRequest())->count);
    }

    /**
     * The conflict case. Inserting a code that another stack already stored must not create a
     * second row, and the object the second stack is holding has to end up pointing at the row
     * that exists — that repointing is resolveUpsertConflicts's entire job.
     *
     * @throws Exception
     */
    public function testAnInsertCollidingOnAUniqueIndexAdoptsTheExistingRowsReference(): void
    {
        $first = $this->insert($this->bootstrap(self::model()), "SHARED", "original");
        $originalReference = (string)$first->objectID->referenceObject;

        $second = $this->insert($this->resolvingContext(), "SHARED", "duplicate");

        $stored = $this->freshContext(self::model())->fetch(UpsertRow::fetchRequest());

        // One row for one code: the unique index turns the second insert into an upsert.
        $this->assertSame(1, $stored->count, "no second row was created");

        // The row that survives is the ORIGINAL one: the upsert overwrites its values but leaves
        // its identity alone, so it keeps the reference the first save assigned it. The object
        // the second stack holds is repointed at that row — which is resolveUpsertConflicts
        // doing its job — so both stacks now identify the same surviving row.
        $this->assertSame($originalReference, (string)$stored->first?->objectID->referenceObject, "the surviving row keeps the original's reference");
        $this->assertSame($originalReference, (string)$second->objectID->referenceObject, "and the colliding object was repointed at it");
        $this->assertSame("duplicate", $stored->first?->label, "the later values won");
    }

    /**
     * The default merge policy refuses a uniqueness collision before the store is involved.
     *
     * This is the other half of the contract, and the reason the collision tests have to install
     * a policy: ManagedObjectContext runs ConflictDetectionService during save, and
     * MergePolicy::error() — the default — raises rather than resolving. So a save that collides
     * on a unique index fails in the context, and the store's own upsert resolution is a
     * downstream step that only a resolving policy ever reaches.
     *
     * @throws Exception
     */
    public function testTheDefaultPolicyRefusesACollisionBeforeTheStoreSeesIt(): void
    {
        $this->insert($this->bootstrap(self::model()), "E1", "original");
        $context = $this->freshContext(self::model());
        $duplicate = new UpsertRow($context);
        $duplicate->code = "E1";
        $duplicate->label = "duplicate";

        $this->expectException(InternalInconsistencyException::class);
        $context->save();
    }

    /**
     * Several colliding inserts in one save are each resolved, not just the first — the
     * resolution loops over every inserted object of the entity.
     *
     * @throws Exception
     */
    public function testEveryCollidingInsertInOneSaveIsResolved(): void
    {
        $seed = $this->bootstrap(self::model());
        $this->insert($seed, "B1", "one");
        $this->insert($seed, "B2", "two");

        $context = $this->resolvingContext();
        $firstDuplicate = new UpsertRow($context);
        $firstDuplicate->code = "B1";
        $firstDuplicate->label = "dup one";
        $secondDuplicate = new UpsertRow($context);
        $secondDuplicate->code = "B2";
        $secondDuplicate->label = "dup two";
        $context->save();

        $stored = $this->freshContext(self::model())->fetch(UpsertRow::fetchRequest())->reduce([],
            /**
             * @param array<string, string> $carry
             * @param UpsertRow $row
             * @return array<string, string>
             */
            static function (array &$carry, UpsertRow $row): array {
                $carry[$row->code] = (string)$row->objectID->referenceObject;
                return $carry;
            });

        $this->assertCount(2, $stored, "two codes, still two rows");
        $this->assertSame($stored["B1"], (string)$firstDuplicate->objectID->referenceObject);
        $this->assertSame($stored["B2"], (string)$secondDuplicate->objectID->referenceObject);
    }

    /**
     * A save that mixes a colliding insert with a fresh one resolves only the collision and
     * leaves the new row alone.
     *
     * @throws Exception
     */
    public function testAFreshInsertAlongsideACollisionIsUntouched(): void
    {
        $existing = $this->insert($this->bootstrap(self::model()), "C1", "original");

        $context = $this->resolvingContext();
        $collides = new UpsertRow($context);
        $collides->code = "C1";
        $collides->label = "duplicate";
        $fresh = new UpsertRow($context);
        $fresh->code = "C2";
        $fresh->label = "new";
        $context->save();

        // The store deduplicates on the unique index, so C1 stays one row and C2 is added: two
        // rows for three inserts across the two saves.
        $this->assertSame(2, $this->freshContext(self::model())->fetch(UpsertRow::fetchRequest())->count, "the collision did not add a row, the fresh insert did");
        $this->assertNotSame((string)$existing->objectID->referenceObject, (string)$fresh->objectID->referenceObject, "the new row keeps its own reference");
        // The colliding object is repointed at the row that already existed; the fresh one is not
        // touched, so a mixed save resolves only what actually collided.
        $this->assertSame((string)$existing->objectID->referenceObject, (string)$collides->objectID->referenceObject, "the colliding object adopts the existing row's reference");
    }

    /**
     * The collision must not disturb rows that merely point AT the surviving row.
     *
     * This is what the primary-key rewrite cost. The generated upsert used to include the primary
     * key in its update clause, so a collision renumbered the existing row to the colliding
     * object's reference — and every foreign key still holding the old value pointed at nothing.
     * A tag attached to the original row came back with a null "row", and the row reported no
     * tags at all: referential data loss with no error anywhere.
     *
     * Asserted through the model in both directions, since a one-sided check would pass on a
     * stale fault.
     *
     * @throws Exception
     */
    public function testACollisionDoesNotOrphanRowsPointingAtTheSurvivingRow(): void
    {
        $seed = $this->bootstrap(self::model());
        $row = $this->insert($seed, "F1", "original");

        $tag = new UpsertTag($seed);
        $tag->title = "attached";
        $tag->row = $row;
        $seed->save();

        $reference = (string)$row->objectID->referenceObject;

        $context = $this->resolvingContext();
        $duplicate = new UpsertRow($context);
        $duplicate->code = "F1";
        $duplicate->label = "duplicate";
        $context->save();

        $verify = $this->freshContext(self::model());
        /** @var UpsertTag|null $storedTag */
        $storedTag = $verify->fetch(UpsertTag::fetchRequest())->first;
        /** @var UpsertRow|null $storedRow */
        $storedRow = $verify->fetch(UpsertRow::fetchRequest())->first;

        $this->assertSame($reference, (string)$storedRow?->objectID->referenceObject, "the surviving row kept its primary key");
        $this->assertNotNull($storedTag?->row, "the tag still points at a row");
        $this->assertSame($reference, (string)$storedTag?->row->objectID->referenceObject, "and it is the surviving row");
        $this->assertSame(1, $storedRow?->tags->count, "the surviving row still owns its tag");
    }

    /**
     * An entity with no indexes skips the resolution entirely — there is no unique value to
     * collide on, so two rows with the same content are two rows.
     *
     * @throws Exception
     */
    public function testAnEntityWithoutIndexesAllowsDuplicateContent(): void
    {
        $context = $this->bootstrap(self::model());
        $first = new PlainRow($context);
        $first->name = "same";
        $second = new PlainRow($context);
        $second->name = "same";
        $context->save();

        $this->assertNotSame(
            (string)$first->objectID->referenceObject,
            (string)$second->objectID->referenceObject,
            "without a unique index the two inserts stay distinct",
        );
        $this->assertSame(2, $this->freshContext(self::model())->fetch(PlainRow::fetchRequest())->count);
    }

    /**
     * A save with nothing inserted reaches the resolution with an empty set and returns without
     * querying — the guard matters because the alternative is a fetch per entity on every save
     * that only updated or deleted.
     *
     * @throws Exception
     */
    public function testASaveWithNoInsertsResolvesNothing(): void
    {
        $context = $this->bootstrap(self::model());
        $row = $this->insert($context, "D1", "before");

        $row->label = "after";
        $context->save();

        $stored = $this->freshContext(self::model())->fetch(UpsertRow::fetchRequest());
        $this->assertSame(1, $stored->count);
        $this->assertSame("after", $stored->first?->label, "the update landed");
    }
}
