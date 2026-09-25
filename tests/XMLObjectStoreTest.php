<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use DOMDocument;
use DOMElement;
use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DeleteRule;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property Set<Carton> $crates
 * @property string $name
 * @method void addCratesObject(Carton $object)
 * @method void removeCratesObject(Carton $object)
 * @method void addCrates(Set<Carton> $objects)
 * @method void removeCrates(Set<Carton> $objects)
 * @method Set<Carton> intersectCrates(Set<Carton> $objects)
 * @method void setCrates(Set<Carton> $objects)
 */
final class Shelf extends ManagedObject
{
}

/**
 * @property string $code
 * @property Shelf $shelf
 * @property int $weight
 */
final class Carton extends ManagedObject
{
}

/**
 * Characterization tests for XMLObjectStore — the atomic store that persists the whole object
 * graph as XML through manual DOM manipulation (~490 lines, previously covered only indirectly
 * by ManagedObjectContextTest). Three historically-reported bugs (updates not reaching the file,
 * cascade-delete DOMException, ignored fetchLimit/sort) are all fixed; these tests pin the
 * current CORRECT behavior so it cannot silently regress.
 *
 * Unlike the context-level tests, several assertions here read the XML file straight off disk and
 * inspect the DOM the store wrote — that is the code path unique to this class (relationship
 * "references"/"destination" bookkeeping, cascade pruning), which a pure object-graph round-trip
 * does not directly verify.
 *
 * A one-to-many Shelf<->Carton model is used: Shelf.crates (to-many, cascade) <-> Carton.shelf
 * (to-one). Each stack that reads the same file gets a freshly built model — entity descriptions
 * are frozen once bound to a coordinator.
 */
final class XMLObjectStoreTest extends TestCase
{
    private URL $storeURL;

    /**
     * A fresh model on every read: entity descriptions freeze once bound to a coordinator, so a memoized one could not open a second stack.
     * @noinspection PhpPropertyOnlyWrittenInspection
     */
    private ManagedObjectModel $model {
        get {
            $shelfName = new AttributeDescription();
            $shelfName->name = "name";
            $shelfName->type = AttributeType::string;

            $crates = new RelationshipDescription();
            $crates->name = "crates";
            $crates->lazyDestinationEntityName = "Carton";
            $crates->lazyInverseRelationshipName = "shelf";
            $crates->isToMany = true;
            $crates->deleteRule = DeleteRule::cascadeDeleteRule;

            $code = new AttributeDescription();
            $code->name = "code";
            $code->type = AttributeType::string;

            $weight = new AttributeDescription();
            $weight->name = "weight";
            $weight->type = AttributeType::integer32;

            $shelf = new RelationshipDescription();
            $shelf->name = "shelf";
            $shelf->lazyDestinationEntityName = "Shelf";
            $shelf->lazyInverseRelationshipName = "crates";
            $shelf->maxCount = 1;

            $shelfEntity = new EntityDescription();
            $shelfEntity->name = "Shelf";
            $shelfEntity->managedObjectClassName = Shelf::class;
            $shelfEntity->properties = new ArrayClass([$shelfName, $crates]);

            $crateEntity = new EntityDescription();
            $crateEntity->name = "Carton";
            $crateEntity->managedObjectClassName = Carton::class;
            $crateEntity->properties = new ArrayClass([$code, $weight, $shelf]);

            $model = new ManagedObjectModel();
            $model->entities = new ArrayClass([$shelfEntity, $crateEntity]);
            return $model;
        }
    }

    /** @throws Exception */
    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator($this->model);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

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

    /**
     * Loads the store file from disk and returns a DOMXPath-free helper: the list of <element>
     * nodes under <elements>.
     *
     * @return list<DOMElement>
     */
    private function elementNodes(): array
    {
        $document = new DOMDocument();
        $document->load($this->storeURL->path);
        $nodes = [];
        foreach ($document->getElementsByTagName("element") as $element) {
            $nodes[] = $element;
        }
        return $nodes;
    }

    /** @throws Exception */
    public function testInsertWritesATypedElementToTheFile(): void
    {
        $context = $this->context();
        $crate = new Carton($context);
        $crate->code = "C-1";
        $crate->weight = 42;
        $context->save();

        $elements = $this->elementNodes();
        $this->assertCount(1, $elements, "one <element> is written for the single inserted object");
        $this->assertSame("Carton", $elements[0]->getAttribute("name"), "the element records its entity name");

        // The attribute values are written as <attribute name=...> children.
        $byName = [];
        foreach ($elements[0]->getElementsByTagName("attribute") as $attribute) {
            $byName[$attribute->getAttribute("name")] = $attribute->nodeValue;
        }
        $this->assertSame("C-1", $byName["code"], "string attribute is written verbatim");
        $this->assertSame("42", $byName["weight"], "integer attribute is written as its scalar text");
    }

    /** @throws Exception */
    public function testTypedAttributesSurviveAFullRoundTrip(): void
    {
        $context = $this->context();
        $crate = new Carton($context);
        $crate->code = "C-7";
        $crate->weight = 128;
        $context->save();

        // Fresh stack reading the same file: values and their types must come back intact.
        $reloaded = $this->context()->fetch(Carton::fetchRequest())->first;
        $this->assertNotNull($reloaded);
        $this->assertSame("C-7", (string)$reloaded->code);
        $this->assertSame(128, $reloaded->weight, "integer attribute round-trips as an int, not a string");
    }

    /** @throws Exception */
    public function testUpdatePersistsToTheFile(): void
    {
        $context = $this->context();
        $crate = new Carton($context);
        $crate->code = "before";
        $context->save();

        $editContext = $this->context();
        $fetched = $editContext->fetch(Carton::fetchRequest())->first;
        $fetched->code = "after";
        $editContext->save();

        $reloaded = $this->context()->fetch(Carton::fetchRequest())->first;
        $this->assertSame("after", (string)$reloaded->code, "an update is persisted and visible to a later stack");
    }

    /** @throws Exception */
    public function testFetchedObjectCarriesAnOriginalSnapshot(): void
    {
        $context = $this->context();
        $carton = new Carton($context);
        $carton->code = "baseline";
        $carton->weight = 11;
        $context->save();

        $reloaded = $this->context()->fetch(Carton::fetchRequest())->first;
        $this->assertNotNull($reloaded, "precondition: the object round-trips");
        $this->assertNotNull($reloaded->originalSnapshot, "a fetched object carries an original snapshot baseline");
        $this->assertSame("baseline", $reloaded->originalSnapshot["code"], "the baseline holds the stored attribute values");
    }

    /** @throws Exception */
    public function testToManyRelationshipPersistsAndTraversesBothWays(): void
    {
        $context = $this->context();
        $shelf = new Shelf($context);
        $shelf->name = "A";
        $first = new Carton($context);
        $first->code = "c1";
        $first->setValueForKey($shelf, "shelf");
        $second = new Carton($context);
        $second->code = "c2";
        $second->setValueForKey($shelf, "shelf");
        $context->save();

        $readContext = $this->context();
        $reloadedShelf = $readContext->fetch(Shelf::fetchRequest())->first;
        $this->assertNotNull($reloadedShelf);
        $codes = $reloadedShelf->crates->map(fn(Carton $c): string => $c->code)->array;
        sort($codes);
        $this->assertSame(["c1", "c2"], $codes, "the to-many side is reconstructed from the stored references");

        $reloadedCrate = $readContext->fetch(Carton::fetchRequest())->first;
        $this->assertSame("A", (string)$reloadedCrate->shelf->name, "the inverse to-one side is reconstructed too");
    }

    /** @throws Exception */
    public function testCascadeDeleteRemovesRelatedObjectsFromTheFile(): void
    {
        $context = $this->context();
        $shelf = new Shelf($context);
        $shelf->name = "doomed";
        $crate = new Carton($context);
        $crate->code = "child";
        $crate->setValueForKey($shelf, "shelf");
        $context->save();

        $this->assertCount(2, $this->elementNodes(), "precondition: shelf + crate both on disk");

        $deleteContext = $this->context();
        $deleteContext->delete($deleteContext->fetch(Shelf::fetchRequest())->first);
        $deleteContext->save();

        $this->assertCount(0, $this->elementNodes(), "cascade delete prunes both the shelf and its crate from the file");

        $readContext = $this->context();
        $this->assertCount(0, $readContext->fetch(Shelf::fetchRequest()), "no shelves remain");
        $this->assertCount(0, $readContext->fetch(Carton::fetchRequest()), "the cascaded crate is gone too");
    }

    /** @throws Exception */
    public function testFetchLimitAndSortAreApplied(): void
    {
        $context = $this->context();
        foreach (["banana", "apple", "cherry"] as $code) {
            $crate = new Carton($context);
            $crate->code = $code;
        }
        $context->save();

        $request = Carton::fetchRequest();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("code", true)]);
        $request->fetchLimit = 2;

        $result = $this->context()->fetch($request);
        $this->assertCount(2, $result, "fetchLimit caps the result count");
        $this->assertSame(
            ["apple", "banana"],
            $result->map(fn(Carton $c): string => $c->code)->array,
            "results are ascending by code and limited to the first two",
        );
    }
}
