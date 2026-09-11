<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\UndoManager;
use Sabatier\Foundation\URL;
use const Sabatier\CoreData\InsertedObjectsKey;

/**
 * @property string $title
 * @property int $pages
 */
final class GraphNote extends ManagedObject
{
}

/** @property string $name */
final class GraphFolder extends ManagedObject
{
}

/**
 * Tests for the parts of ManagedObjectContext a consumer drives directly but the rest of the
 * suite never reaches: undo and redo, refreshing objects back into faults, assigning a store,
 * and merging changes across contexts.
 *
 * These are the context's own API rather than the machinery behind a fetch or a save, which is
 * why they were uncovered — every other suite exercises the context by saving through it. They
 * are also the operations whose failure is quiet: an undo that does nothing, a refresh that
 * leaves stale values in place, or a merge that drops an insertion all look like working code
 * from the outside.
 *
 * An XML-backed store keeps this fast; nothing here depends on SQL.
 */
final class ManagedObjectContextGraphTest extends TestCase
{
    private string $storePath;
    private ManagedObjectContext $context;
    private PersistentStore $store;

    /** A Note with an optional to-one Folder, so relationship faults have somewhere to point. */
    private static function model(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $pages = new AttributeDescription();
        $pages->name = "pages";
        $pages->type = AttributeType::integer32;
        $pages->isOptional = true;

        $folder = new RelationshipDescription();
        $folder->name = "folder";
        $folder->lazyDestinationEntityName = "GraphFolder";
        $folder->lazyInverseRelationshipName = "notes";
        $folder->isOptional = true;

        $note = new EntityDescription();
        $note->name = "GraphNote";
        $note->managedObjectClassName = GraphNote::class;
        $note->properties = new ArrayClass([$title, $pages, $folder]);

        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $notes = new RelationshipDescription();
        $notes->name = "notes";
        $notes->lazyDestinationEntityName = "GraphNote";
        $notes->lazyInverseRelationshipName = "folder";
        $notes->isToMany = true;
        $notes->isOptional = true;

        $folderEntity = new EntityDescription();
        $folderEntity->name = "GraphFolder";
        $folderEntity->managedObjectClassName = GraphFolder::class;
        $folderEntity->properties = new ArrayClass([$name, $notes]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$note, $folderEntity]);
        return $model;
    }

    /** @throws Exception */
    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-graph-test-" . uniqid("", true) . ".xml";
        $storeURL = new URL("file://" . str_replace("\\", "/", $this->storePath));

        $coordinator = new PersistentStoreCoordinator(self::model());
        $this->store = $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $storeURL);
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->context->persistentStoreCoordinator = null;
        if (is_file($this->storePath)) {
            unlink($this->storePath);
        }
    }

    /** @throws Exception */
    private function savedNote(string $title, int $pages = 1): GraphNote
    {
        $note = new GraphNote($this->context);
        $note->title = $title;
        $note->pages = $pages;
        $this->context->save();
        return $note;
    }

    // --- Undo and redo ---

    /**
     * With no undo manager attached, undo and redo are no-ops rather than errors. That is the
     * default state of a context, so a caller that offers undo optionally must be able to call
     * them unconditionally.
     */
    public function testUndoAndRedoAreHarmlessWithoutAnUndoManager(): void
    {
        $this->assertNull($this->context->undoManager, "a context has no undo manager by default");

        $this->context->undo();
        $this->context->redo();

        $this->assertFalse($this->context->hasChanges, "nothing happened, and nothing broke");
    }

    /**
     * undo() and redo() forward to the attached manager and nothing else: the context never
     * registers an undo operation, so an edit is NOT reversed by them today. Measured rather
     * than assumed — grepping the source shows undoManager is referenced only by the property
     * and these two delegating calls.
     *
     * That is the same kind of reserved-but-unimplemented member the framework keeps elsewhere
     * to honour the shape of Core Data's contract, so this pins the current behaviour instead of
     * asserting the one the name suggests. If registration is ever wired up, this case is where
     * it will fail, which is what makes it worth keeping.
     *
     * @throws Exception
     */
    public function testUndoForwardsToTheManagerButNothingRegistersOperations(): void
    {
        $manager = new UndoManager();
        $this->context->undoManager = $manager;
        $note = $this->savedNote("original", 1);

        $this->context->processPendingChanges();
        $note->title = "edited";
        $this->context->processPendingChanges();

        $this->assertFalse($manager->canUndo, "the context registered no undo operation");

        $this->context->undo();

        $this->assertSame("edited", $note->valueForKey("title"), "so undo leaves the edit in place");
    }

    // --- Refreshing ---

    /**
     * Refreshing turns the object back into a fault, which is what the next access then fires.
     *
     * The value that comes back is the pending one, not the committed one, and that surprised me
     * enough to measure it: turnObjectIntoFault restores each attribute from
     * committedValues(null) — which really does still say "stored" — but clears the object's
     * loaded attributes and marks it a fault, so reading the property fires the fault and the
     * context hands back the edit it is still tracking. What refresh() drops is the
     * materialization, not the pending change.
     *
     * @throws Exception
     */
    public function testRefreshTurnsTheObjectIntoAFaultAndKeepsTheTrackedEdit(): void
    {
        $note = $this->savedNote("stored", 1);
        $note->title = "pending";

        $this->assertSame("stored", $note->committedValues(null)["title"], "the committed value is the saved one");
        $this->assertFalse($note->isFault, "editing materialized it");

        $this->context->refresh($note);

        // isFault is checked before any property is read, because reading one fires the fault
        // and would erase the very effect under test.
        $this->assertTrue($note->isFault, "refresh turned it back into a fault");
        $this->assertSame("pending", $note->valueForKey("title"), "and firing that fault returns the change the context still holds");
    }

    /**
     * With mergeChanges, the pending edits are explicitly reapplied over the refreshed values
     * rather than left to the fault: refault() captures changedValues() first and writes them
     * back through setPrimitiveValueForKey afterwards. The observable result matches the default
     * path here, but the mechanism is the one a caller relies on when the store has moved on.
     *
     * @throws Exception
     */
    public function testRefreshWithMergeChangesKeepsPendingEdits(): void
    {
        $note = $this->savedNote("stored", 1);
        $note->title = "pending";

        $this->context->refresh($note, true);

        $this->assertTrue($note->isFault, "the object was refreshed");
        $this->assertSame("pending", $note->valueForKey("title"), "and the edit was merged back over the refreshed values");
    }

    /**
     * refreshAllObjects applies the same treatment to everything registered, which is what a
     * caller does after learning the store changed underneath it.
     *
     * @throws Exception
     */
    public function testRefreshAllObjectsRefreshesEveryRegisteredObject(): void
    {
        $first = $this->savedNote("first", 1);
        $second = $this->savedNote("second", 2);
        $first->title = "edited first";
        $second->title = "edited second";

        $this->assertFalse($first->isFault);
        $this->assertFalse($second->isFault);

        $this->context->refreshAllObjects();

        // Both objects must be faults again — that is what distinguishes this from doing nothing.
        // Checked before reading any property, since a read fires the fault.
        $this->assertTrue($first->isFault, "every registered object was refreshed");
        $this->assertTrue($second->isFault);

        // refreshAllObjects passes mergeChanges: true, so the tracked edits survive.
        $this->assertSame("edited first", $first->valueForKey("title"));
        $this->assertSame("edited second", $second->valueForKey("title"));
    }

    // --- Store assignment ---

    /**
     * Assigning an object to a store obtains a permanent ID for it. A freshly inserted object
     * has a temporary one, and that is what distinguishes the two.
     *
     * @throws Exception
     */
    public function testAssigningToAStoreLeavesAPermanentID(): void
    {
        $note = new GraphNote($this->context);
        $note->title = "unsaved";

        // Measured, and worth recording: over an atomic store an inserted object already has a
        // permanent id before it is saved, because the store allocates the reference at
        // insertion rather than at save time.
        //
        // A consequence worth being honest about: on this backend assign() has no observable
        // effect, and emptying its body leaves this case green. Pinning that it is harmless and
        // does not reallocate the id is all an atomic store can show; the branch where it does
        // the work belongs with a SQL-backed case, where ids stay temporary until save.
        $this->assertFalse($note->objectID->isTemporaryID, "an atomic store hands out permanent ids up front");

        $before = (string)$note->objectID;

        $this->context->assign($note, $this->store);

        $this->assertSame($before, (string)$note->objectID, "an id that is already permanent is not reallocated");
        $this->assertSame($this->store, $this->context->persistentStoreCoordinator?->persistentStores->first, "the store it was assigned to is the one backing the context");
    }

    // --- Merging across contexts ---

    /**
     * mergeChangesFromRemoteContextSave inserts the reported objects into every context that
     * does not already own them. The owning context is skipped, which is what stops an object
     * being inserted into the context it came from.
     *
     * @throws Exception
     */
    public function testMergingARemoteSaveInsertsIntoTheOtherContexts(): void
    {
        $note = new GraphNote($this->context);
        $note->title = "from the other context";

        $other = new ManagedObjectContext();
        $other->persistentStoreCoordinator = $this->context->persistentStoreCoordinator;

        ManagedObjectContext::mergeChangesFromRemoteContextSave(
            new Dictionary([InsertedObjectsKey => new ArrayClass([$note])]),
            new ArrayClass([$this->context, $other]),
        );

        $this->assertTrue($other->insertedObjects->containsElement($note), "the other context received it");

        $other->persistentStoreCoordinator = null;
    }

    /**
     * A merge carrying nothing is a no-op. Worth pinning because the notification payload is a
     * dictionary whose keys may simply be absent, and reading a missing key must not fail.
     *
     * @throws Exception
     */
    public function testMergingAnEmptyRemoteSaveDoesNothing(): void
    {
        $other = new ManagedObjectContext();
        $other->persistentStoreCoordinator = $this->context->persistentStoreCoordinator;

        ManagedObjectContext::mergeChangesFromRemoteContextSave(new Dictionary(), new ArrayClass([$other]));

        $this->assertTrue($other->insertedObjects->isEmpty);

        $other->persistentStoreCoordinator = null;
    }
}
