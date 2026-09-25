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
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Set;

/**
 * @property Set<SQLLedgerEntry>|null $entries
 * @property string $name
 * @method void addEntriesObject(SQLLedgerEntry $object)
 * @method void removeEntriesObject(SQLLedgerEntry $object)
 * @method void addEntries(Set<SQLLedgerEntry> $objects)
 * @method void removeEntries(Set<SQLLedgerEntry> $objects)
 * @method Set<SQLLedgerEntry> intersectEntries(Set<SQLLedgerEntry> $objects)
 * @method void setEntries(Set<SQLLedgerEntry> $objects)
 */
final class SQLLedgerOwner extends ManagedObject
{
}

/**
 * @property double|null $amount
 * @property string $name
 * @property SQLLedgerOwner|null $owner
 */
final class SQLLedgerEntry extends ManagedObject
{
}

/**
 * The SQL half of the scope question answered by RelationshipSurvivesAttributeMutationTest:
 * mutating a plain attribute on a saved object must not disturb its relationships.
 *
 * Mutating any attribute fires KVO, which fulfills the object's fault, and fulfilling a fault
 * deliberately re-faults every to-one relationship — writing null so the value is re-resolved
 * from the store on next access. That design is only sound while the store can actually answer
 * the re-resolution. The atomic stores could not (AtomicStore::newValueForRelationship had no
 * branch for a to-one whose inverse is to-many), so the relationship was re-faulted and never
 * came back; SQLCore issues a real query through SQLRelationshipFaultRequestContext and has no
 * such gap.
 *
 * This case pins that difference where production actually runs. It is the same scenario as
 * the XML one, entity for entity, so the two can be compared directly: if a future change to
 * the faulting protocol breaks the re-resolution contract, the store that regresses is named
 * by whichever of the two files fails.
 *
 * Reuses SQLMigrationTestCase purely for its database lifecycle (temporary .env, drop/create
 * around each test, bootstrap/freshContext helpers); no migration is exercised.
 */
final class SQLRelationshipSurvivesAttributeMutationTest extends SQLMigrationTestCase
{
    /** SQLLedgerOwner 1 <-> * SQLLedgerEntry, the ordinary to-one/to-many pair. */
    private static function makeModel(): ManagedObjectModel
    {
        $ownerName = new AttributeDescription();
        $ownerName->name = "name";
        $ownerName->type = AttributeType::string;

        $entries = new RelationshipDescription();
        $entries->name = "entries";
        $entries->lazyDestinationEntityName = "SQLLedgerEntry";
        $entries->lazyInverseRelationshipName = "owner";
        $entries->isToMany = true;
        $entries->isOptional = true;

        $owner = new EntityDescription();
        $owner->name = "SQLLedgerOwner";
        $owner->managedObjectClassName = SQLLedgerOwner::class;
        $owner->properties = new ArrayClass([$ownerName, $entries]);

        $entryName = new AttributeDescription();
        $entryName->name = "name";
        $entryName->type = AttributeType::string;

        // The attribute mutated in the test: unrelated to the relationship, and optional so
        // that leaving it unset before the first save is legal.
        $amount = new AttributeDescription();
        $amount->name = "amount";
        $amount->type = AttributeType::double;
        $amount->isOptional = true;

        $ownerRef = new RelationshipDescription();
        $ownerRef->name = "owner";
        $ownerRef->lazyDestinationEntityName = "SQLLedgerOwner";
        $ownerRef->lazyInverseRelationshipName = "entries";
        $ownerRef->isOptional = true;

        $entry = new EntityDescription();
        $entry->name = "SQLLedgerEntry";
        $entry->managedObjectClassName = SQLLedgerEntry::class;
        $entry->properties = new ArrayClass([$entryName, $amount, $ownerRef]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$owner, $entry]);
        return $model;
    }

    /** @throws Exception */
    public function testMutatingAnAttributeKeepsTheToOneRelationship(): void
    {
        $context = $this->bootstrap(self::makeModel());
        $owner = new SQLLedgerOwner($context);
        $owner->name = "owner";
        $entry = new SQLLedgerEntry($context);
        $entry->name = "entry";
        $entry->owner = $owner;
        $context->save();

        $this->assertSame($owner, $entry->owner, "precondition: the to-one survives the first save");

        $entry->amount = 900.0;

        $this->assertNotNull($entry->owner, "mutating an unrelated attribute must not clear the to-one");
        $this->assertSame($owner, $entry->owner, "and it still points at the same object");
    }

    /** @throws Exception */
    public function testMutatingAnAttributeKeepsTheToManyInverse(): void
    {
        $context = $this->bootstrap(self::makeModel());
        $owner = new SQLLedgerOwner($context);
        $owner->name = "owner";
        $entry = new SQLLedgerEntry($context);
        $entry->name = "entry";
        $entry->owner = $owner;
        $context->save();

        $this->assertSame(1, $owner->entries?->count ?? 0, "precondition: the to-many inverse survives the first save");

        $entry->amount = 900.0;

        $this->assertSame(1, $owner->entries?->count ?? 0, "mutating an unrelated attribute must not empty the to-many inverse");
    }

    /**
     * The symptom as it first surfaced: a predicate that traverses the relationship stops
     * matching, while one over the object's own attribute still finds it.
     *
     * @throws Exception
     */
    public function testRelationshipIsQueryableAfterMutateAndSave(): void
    {
        $context = $this->bootstrap(self::makeModel());
        $owner = new SQLLedgerOwner($context);
        $owner->name = "owner";
        $entry = new SQLLedgerEntry($context);
        $entry->name = "entry";
        $entry->owner = $owner;
        $context->save();

        $entry->amount = 900.0;
        $context->save();

        $freshContext = $this->freshContext(self::makeModel());
        $reloadedOwner = $freshContext->fetch(SQLLedgerOwner::fetchRequest())->first;
        $this->assertNotNull($reloadedOwner, "precondition: the owner is still in the database");
        $this->assertSame(1, $reloadedOwner->entries?->count ?? 0, "the link survived the round trip through the database");

        $reloadedEntry = $freshContext->fetch(SQLLedgerEntry::fetchRequest())->first;
        $this->assertNotNull($reloadedEntry, "the entry is still in the database");
        $this->assertNotNull($reloadedEntry->owner, "and it still knows its owner");
    }

    /**
     * The SQL half of RelationshipSurvivesAttributeMutationTest's inverse-maintenance cases.
     *
     * @return array{0: SQLLedgerOwner, 1: SQLLedgerOwner, 2: SQLLedgerEntry}
     * @throws Exception
     */
    private function loadedPairOfOwners(): array
    {
        $seed = $this->bootstrap(self::makeModel());
        $first = new SQLLedgerOwner($seed);
        $first->name = "first";
        $second = new SQLLedgerOwner($seed);
        $second->name = "second";
        $entry = new SQLLedgerEntry($seed);
        $entry->name = "entry";
        $first->addEntriesObject($entry);
        $seed->save();

        $context = $this->freshContext(self::makeModel());
        /** @var Dictionary<SQLLedgerOwner> $byName */
        $byName = $context->fetch(SQLLedgerOwner::fetchRequest())->reduce(new Dictionary(),
            static function (Dictionary $owners, SQLLedgerOwner $owner): Dictionary {
                $owners[$owner->name] = $owner;
                return $owners;
            });
        $loadedEntry = $context->fetch(SQLLedgerEntry::fetchRequest())->first;
        $this->assertNotNull($loadedEntry);
        return [$byName["first"], $byName["second"], $loadedEntry];
    }

    /** @throws Exception */
    public function testReassigningAToOneMovesItBetweenTheInverses(): void
    {
        [$first, $second, $entry] = $this->loadedPairOfOwners();
        $this->assertSame(1, $first->entries?->count ?? -1, "precondition: the entry starts on the first owner");

        $entry->owner = $second;

        $this->assertSame(0, $first->entries?->count ?? -1, "the owner it left drops it");
        $this->assertSame(1, $second->entries?->count ?? -1, "and the owner it moved to holds it");
    }

    /** @throws Exception */
    public function testNullingAToOneRemovesItFromTheInverse(): void
    {
        [$first, , $entry] = $this->loadedPairOfOwners();

        $entry->owner = null;

        $this->assertSame(0, $first->entries?->count ?? -1, "clearing the to-one empties the inverse it was in");
    }

    /**
     * Two owners and an entry, all inserted in this context and never saved.
     *
     * @return array{0: SQLLedgerOwner, 1: SQLLedgerOwner, 2: SQLLedgerEntry, 3: ManagedObjectContext}
     * @throws Exception
     */
    private function unsavedPairOfOwners(): array
    {
        $context = $this->bootstrap(self::makeModel());
        $first = new SQLLedgerOwner($context);
        $first->name = "first";
        $second = new SQLLedgerOwner($context);
        $second->name = "second";
        $entry = new SQLLedgerEntry($context);
        $entry->name = "entry";
        return [$first, $second, $entry, $context];
    }

    /** @throws Exception */
    public function testAssigningAToOneOnAnUnsavedObjectPopulatesTheInverse(): void
    {
        [$first, , $entry] = $this->unsavedPairOfOwners();

        $entry->owner = $first;

        $this->assertTrue($first->entries?->containsElement($entry) ?? false, "the inverse holds the entry before any save");
    }

    /** @throws Exception */
    public function testReassigningAToOneOnAnUnsavedObjectMovesItBetweenTheInverses(): void
    {
        [$first, $second, $entry] = $this->unsavedPairOfOwners();
        /** @noinspection PhpFieldImmediatelyRewrittenInspection */
        $entry->owner = $first;

        $entry->owner = $second;

        $this->assertSame(0, $first->entries?->count ?? -1, "the owner it left drops it");
        $this->assertSame(1, $second->entries?->count ?? -1, "and the owner it moved to holds it");
    }

    /** @throws Exception */
    public function testNullingAToOneOnAnUnsavedObjectRemovesItFromTheInverse(): void
    {
        [$first, , $entry] = $this->unsavedPairOfOwners();
        /** @noinspection PhpFieldImmediatelyRewrittenInspection */
        $entry->owner = $first;

        $entry->owner = null;

        $this->assertSame(0, $first->entries?->count ?? -1, "clearing the to-one empties the inverse it was in");
    }

    /**
     * The link an unsaved assignment records must still be the one the store writes.
     *
     * @throws Exception
     */
    public function testAnUnsavedAssignmentPersistsTheRelationship(): void
    {
        [$first, $second, $entry, $context] = $this->unsavedPairOfOwners();
        /** @noinspection PhpFieldImmediatelyRewrittenInspection */
        $entry->owner = $first;
        $entry->owner = $second;
        $context->save();

        $reloaded = $this->freshContext(self::makeModel());
        $loadedEntry = $reloaded->fetch(SQLLedgerEntry::fetchRequest())->first;
        $this->assertNotNull($loadedEntry);
        $this->assertSame("second", $loadedEntry->owner?->name, "the entry reads back under the owner it was last assigned to");
    }
}
