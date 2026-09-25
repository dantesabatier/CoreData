<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use DOMDocument;
use DOMXPath;
use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property Set<LedgerEntry>|null $entries
 * @property string $name
 * @method void addEntriesObject(LedgerEntry $object)
 * @method void removeEntriesObject(LedgerEntry $object)
 * @method void addEntries(Set<LedgerEntry> $objects)
 * @method void removeEntries(Set<LedgerEntry> $objects)
 * @method Set<LedgerEntry> intersectEntries(Set<LedgerEntry> $objects)
 * @method void setEntries(Set<LedgerEntry> $objects)
 */
final class LedgerOwner extends ManagedObject
{
}

/**
 * @property double|null $amount
 * @property string $name
 * @property LedgerOwner|null $owner
 */
final class LedgerEntry extends ManagedObject
{
}

/**
 * Mutating a plain attribute on a saved object must not disturb its relationships.
 *
 * Two defects in the atomic stores made it do exactly that. Both are pinned here because they
 * present as one symptom.
 *
 *  - FIXED — the to-one could not be re-resolved. Mutating any attribute fires KVO, which
 *    fulfills the object's fault, and FaultHandler::fulfillFault deliberately re-faults every
 *    to-one: it writes null so the value is read back from the store on next access. That is
 *    sound only while the store can answer, and AtomicStore::newValueForRelationship could not.
 *    It had branches for to-many/to-many, to-many/to-one and to-one/to-one, but the fourth
 *    combination — a to-one whose inverse is to-many, the ordinary shape — fell through to the
 *    final "return Nil". So the relationship was re-faulted and never came back, even though
 *    the cache node held the right ManagedObjectID all along.
 *
 *  - FIXED — an unresolved fault was written back to the store as though it were a value.
 *    XMLObjectStore serializes whatever the object currently holds, and an unresolved
 *    relationship holds nothing: an empty set for a to-many, null for a to-one. Both are
 *    recorded as fact, in the cache node and in the document alike, so the damage outlives the
 *    context that caused it. On insert this empties the to-many inverse (objects are cached one
 *    at a time, so the first side saved has an inverse whose members are not yet in the node
 *    cache), which is why the inverse reads 0 from the very first save, before any mutation. On
 *    a later save it erases the to-one, because fulfilling a fault has just re-faulted it.
 *
 *    Skipping every relationship fault is not enough on its own: a newly-built FaultingSet may
 *    already contain explicit members, and assigning null or an empty set must still reach the
 *    store. XMLObjectStore therefore preserves only empty, unchanged faults; explicit members
 *    and keys present in changedValuesForCurrentEvent are serialized normally.
 *
 * Surfaced in Raya, where Size::willSave() fetches activities with a "task.size = $this"
 * predicate: the fetch worked in production (SQL) but returned nothing under test (XML),
 * because the scaffolding mutated an activity between saves. The SQL store issues a real query
 * through SQLRelationshipFaultRequestContext and has no such gap; the difference is pinned by
 * SQLRelationshipSurvivesAttributeMutationTest, which runs this same scenario entity for entity
 * against a real database.
 */
final class RelationshipSurvivesAttributeMutationTest extends TestCase
{
    private URL $storeURL;

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");
    }

    /** @throws Exception */
    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    /** LedgerOwner 1 <-> * LedgerEntry, the ordinary to-one/to-many pair. */
    private static function makeModel(): ManagedObjectModel
    {
        $ownerName = new AttributeDescription();
        $ownerName->name = "name";
        $ownerName->type = AttributeType::string;

        $entries = new RelationshipDescription();
        $entries->name = "entries";
        $entries->lazyDestinationEntityName = "LedgerEntry";
        $entries->lazyInverseRelationshipName = "owner";
        $entries->isToMany = true;
        $entries->isOptional = true;

        $owner = new EntityDescription();
        $owner->name = "LedgerOwner";
        $owner->managedObjectClassName = LedgerOwner::class;
        $owner->properties = new ArrayClass([$ownerName, $entries]);

        $entryName = new AttributeDescription();
        $entryName->name = "name";
        $entryName->type = AttributeType::string;

        // The attribute mutated in the tests: unrelated to the relationship, and optional so
        // that leaving it unset before the first save is legal.
        $amount = new AttributeDescription();
        $amount->name = "amount";
        $amount->type = AttributeType::double;
        $amount->isOptional = true;

        $ownerRef = new RelationshipDescription();
        $ownerRef->name = "owner";
        $ownerRef->lazyDestinationEntityName = "LedgerOwner";
        $ownerRef->lazyInverseRelationshipName = "entries";
        $ownerRef->isOptional = true;

        $entry = new EntityDescription();
        $entry->name = "LedgerEntry";
        $entry->managedObjectClassName = LedgerEntry::class;
        $entry->properties = new ArrayClass([$entryName, $amount, $ownerRef]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$owner, $entry]);
        return $model;
    }

    /**
     * A fresh stack over the test's store file; a new model each time, as descriptions freeze once assigned.
     *
     * @throws Exception
     */
    private function makeContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makeModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * An owner with one entry attached, saved.
     *
     * @return array{0: LedgerOwner, 1: LedgerEntry}
     * @throws Exception
     */
    private function makeSavedPair(ManagedObjectContext $context): array
    {
        $owner = new LedgerOwner($context);
        $owner->name = "owner";
        $entry = new LedgerEntry($context);
        $entry->name = "entry";
        $entry->owner = $owner;
        $context->save();
        return [$owner, $entry];
    }

    private function relationshipReferences(string $entityName, string $relationshipName): string
    {
        $document = new DOMDocument();
        $document->load($this->storeURL->path);
        $xpath = new DOMXPath($document);
        $node = $xpath->query("//element[@name='$entityName']/relationship[@name='$relationshipName']")->item(0);
        $this->assertNotNull($node, "the relationship is present in the XML store");
        return $node->attributes?->getNamedItem("references")?->nodeValue ?? "";
    }

    /**
     * The to-many inverse must be populated by the save that establishes it — this fails
     * before any mutation, so it is the half of the defect that does not involve faulting.
     *
     * @throws Exception
     */
    public function testToManyInverseIsPopulatedAfterTheFirstSave(): void
    {
        $context = $this->makeContext();
        [$owner, $entry] = $this->makeSavedPair($context);

        $this->assertSame(1, $owner->entries?->count ?? 0, "the to-many inverse holds the entry after the save that linked it");
        $this->assertSame((string)$entry->objectID->referenceObject, $this->relationshipReferences("LedgerOwner", "entries"), "the inverse reference reached the XML document");
    }

    /** @throws Exception */
    public function testMutatingAnAttributeKeepsTheToOneRelationship(): void
    {
        $context = $this->makeContext();
        [$owner, $entry] = $this->makeSavedPair($context);
        $this->assertSame($owner, $entry->owner, "precondition: the to-one survives the first save");

        $entry->amount = 900.0;

        $this->assertNotNull($entry->owner, "mutating an unrelated attribute must not clear the to-one");
        $this->assertSame($owner, $entry->owner, "and it still points at the same object");
    }

    /** @throws Exception */
    public function testMutatingAnAttributeKeepsTheToManyInverse(): void
    {
        $context = $this->makeContext();
        [$owner, $entry] = $this->makeSavedPair($context);

        $entry->amount = 900.0;

        $this->assertSame(1, $owner->entries?->count ?? 0, "mutating an unrelated attribute must not empty the to-many inverse");
    }

    /**
     * The symptom as it first surfaced in Raya: after mutate-then-save, a predicate that
     * traverses the relationship stops matching, while one over the object's own attribute
     * still finds the object. Both are asserted so a regression cannot be mistaken for the
     * object having gone missing entirely.
     *
     * @throws Exception
     */
    public function testRelationshipPredicateStillMatchesAfterMutateAndSave(): void
    {
        $context = $this->makeContext();
        [$owner, $entry] = $this->makeSavedPair($context);

        $entry->amount = 900.0;
        $context->save();

        $byRelationship = LedgerEntry::fetchRequest();
        $byRelationship->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("owner"),
            Expression::expressionForConstantValue($owner),
        );
        $this->assertSame(1, $context->fetch($byRelationship)->count, "a predicate over the relationship still finds the entry");

        $byAttribute = LedgerEntry::fetchRequest();
        $byAttribute->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("name"),
            Expression::expressionForConstantValue("entry"),
        );
        $this->assertSame(1, $context->fetch($byAttribute)->count, "and so does one over its own attribute");
    }

    /**
     * The link must be on disk too, not merely repaired in the context that wrote it.
     *
     * @throws Exception
     */
    public function testRelationshipSurvivesReloadingTheStore(): void
    {
        $context = $this->makeContext();
        [, $entry] = $this->makeSavedPair($context);
        $entry->amount = 900.0;
        $context->save();

        $freshContext = $this->makeContext();
        $reloadedOwner = $freshContext->fetch(LedgerOwner::fetchRequest())->first;
        $this->assertNotNull($reloadedOwner, "precondition: the owner is still in the store");
        $this->assertSame(1, $reloadedOwner->entries?->count ?? 0, "the to-many inverse survived the round trip through the file");

        $reloadedEntry = $freshContext->fetch(LedgerEntry::fetchRequest())->first;
        $this->assertNotNull($reloadedEntry, "the entry is still in the store");
        $this->assertNotNull($reloadedEntry->owner, "and it still knows its owner");
    }

    /** @throws Exception */
    public function testUnrelatedSavePreservesButExplicitNullClearsTheToOneReference(): void
    {
        $context = $this->makeContext();
        [$owner, $entry] = $this->makeSavedPair($context);

        $entry->amount = 900.0;
        $context->save();
        $this->assertSame((string)$owner->objectID->referenceObject, $this->relationshipReferences("LedgerEntry", "owner"), "an untouched fault preserves the stored reference");

        $entry->owner = null;
        $context->save();
        $this->assertSame("", $this->relationshipReferences("LedgerEntry", "owner"), "an explicitly assigned null is persisted");
        $this->assertSame("", $this->relationshipReferences("LedgerOwner", "entries"), "the stored inverse is cleared too");

        $freshContext = $this->makeContext();
        $reloadedEntry = $freshContext->fetch(LedgerEntry::fetchRequest())->first;
        $this->assertNotNull($reloadedEntry);
        $this->assertNull($reloadedEntry->owner, "the explicit null survives a fresh read of the store");
    }

    /**
     * Assigning the to-one end must maintain the to-many inverse, as the other three cardinalities do.
     *
     * Read back through a context that loaded the graph from the file: an object hydrated that way
     * arrives inserted and awake, which is what lets the assignment resolve the old value and know
     * which inverse to take the entry out of.
     *
     * @return array{0: LedgerOwner, 1: LedgerOwner, 2: LedgerEntry}
     * @throws Exception
     */
    private function loadedPairOfOwners(ManagedObjectContext $seed): array
    {
        $first = new LedgerOwner($seed);
        $first->name = "first";
        $second = new LedgerOwner($seed);
        $second->name = "second";
        $entry = new LedgerEntry($seed);
        $entry->name = "entry";
        $first->addEntriesObject($entry);
        $seed->save();

        $context = $this->makeContext();
        /** @var Dictionary<LedgerOwner> $byName */
        $byName = $context->fetch(LedgerOwner::fetchRequest())->reduce(new Dictionary(),
            static function (Dictionary $owners, LedgerOwner $owner): Dictionary {
                $owners[$owner->name] = $owner;
                return $owners;
            });
        $loadedEntry = $context->fetch(LedgerEntry::fetchRequest())->first;
        $this->assertNotNull($loadedEntry);
        return [$byName["first"], $byName["second"], $loadedEntry];
    }

    /** @throws Exception */
    public function testReassigningAToOneMovesItBetweenTheInverses(): void
    {
        [$first, $second, $entry] = $this->loadedPairOfOwners($this->makeContext());
        $this->assertSame(1, $first->entries?->count ?? -1, "precondition: the entry starts on the first owner");

        $entry->owner = $second;

        $this->assertSame(0, $first->entries?->count ?? -1, "the owner it left drops it");
        $this->assertSame(1, $second->entries?->count ?? -1, "and the owner it moved to holds it");
    }

    /** @throws Exception */
    public function testNullingAToOneRemovesItFromTheInverse(): void
    {
        [$first, , $entry] = $this->loadedPairOfOwners($this->makeContext());

        $entry->owner = null;

        $this->assertSame(0, $first->entries?->count ?? -1, "clearing the to-one empties the inverse it was in");
    }

    /**
     * Two owners and an entry, all inserted in this context and never saved.
     *
     * @return array{0: LedgerOwner, 1: LedgerOwner, 2: LedgerEntry}
     */
    private function unsavedPairOfOwners(ManagedObjectContext $context): array
    {
        $first = new LedgerOwner($context);
        $first->name = "first";
        $second = new LedgerOwner($context);
        $second->name = "second";
        $entry = new LedgerEntry($context);
        $entry->name = "entry";
        return [$first, $second, $entry];
    }

    /** @throws Exception */
    public function testAssigningAToOneOnAnUnsavedObjectPopulatesTheInverse(): void
    {
        [$first, , $entry] = $this->unsavedPairOfOwners($this->makeContext());

        $entry->owner = $first;

        $this->assertTrue($first->entries?->containsElement($entry) ?? false, "the inverse holds the entry before any save");
    }

    /** @throws Exception */
    public function testReassigningAToOneOnAnUnsavedObjectMovesItBetweenTheInverses(): void
    {
        [$first, $second, $entry] = $this->unsavedPairOfOwners($this->makeContext());
        /** @noinspection PhpFieldImmediatelyRewrittenInspection */
        $entry->owner = $first;

        $entry->owner = $second;

        $this->assertSame(0, $first->entries?->count ?? -1, "the owner it left drops it");
        $this->assertSame(1, $second->entries?->count ?? -1, "and the owner it moved to holds it");
    }

    /** @throws Exception */
    public function testNullingAToOneOnAnUnsavedObjectRemovesItFromTheInverse(): void
    {
        [$first, , $entry] = $this->unsavedPairOfOwners($this->makeContext());
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
        $context = $this->makeContext();
        [$first, $second, $entry] = $this->unsavedPairOfOwners($context);
        /** @noinspection PhpFieldImmediatelyRewrittenInspection */
        $entry->owner = $first;
        $entry->owner = $second;
        $context->save();

        $reloaded = $this->makeContext();
        $loadedEntry = $reloaded->fetch(LedgerEntry::fetchRequest())->first;
        $this->assertNotNull($loadedEntry);
        $this->assertSame("second", $loadedEntry->owner?->name, "the entry reads back under the owner it was last assigned to");
    }
}
