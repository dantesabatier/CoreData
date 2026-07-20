<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use DOMDocument;
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
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;

final class Shelf extends ManagedObject
{
}

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
    private string $storePath;
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
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

    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-xmlstore-test-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    /**
     * Loads the store file from disk and returns a DOMXPath-free helper: the list of <element>
     * nodes under <elements>.
     *
     * @return list<\DOMElement>
     */
    private function elementNodes(): array
    {
        $document = new DOMDocument();
        $document->load($this->storePath);
        $nodes = [];
        foreach ($document->getElementsByTagName("element") as $element) {
            $nodes[] = $element;
        }
        return $nodes;
    }

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
        $codes = $reloadedShelf->crates->map(fn(Carton $c): string => (string)$c->code)->array;
        sort($codes);
        $this->assertSame(["c1", "c2"], $codes, "the to-many side is reconstructed from the stored references");

        $reloadedCrate = $readContext->fetch(Carton::fetchRequest())->first;
        $this->assertSame("A", (string)$reloadedCrate->shelf->name, "the inverse to-one side is reconstructed too");
    }

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
            $result->map(fn(Carton $c): string => (string)$c->code)->array,
            "results are ascending by code and limited to the first two",
        );
    }
}
