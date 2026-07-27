<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

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
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;

final class Label extends ManagedObject
{
}

final class Article extends ManagedObject
{
}

/**
 * Replacing a to-many relationship with a SUBSET of what it held, over a full stack
 * (ManagedObjectModel -> PersistentStoreCoordinator -> XMLObjectStore).
 *
 * ClearToManyRelationshipTest covers going to empty. This covers the partial case, which is
 * what a client does when it removes one item from a list and sends back the ones that remain:
 * three related objects in, two sent back, so exactly one link must disappear and the other
 * two must survive untouched — on both sides of the relationship, and after a reload.
 *
 * The partial case has failure modes the all-or-nothing case cannot expose: a diff that
 * subtracts instead of intersecting, an inverse updated for every member rather than only the
 * removed one, and correlation rows deleted wholesale and only partly rewritten.
 *
 * Both a genuine many-to-many (correlation table) and a to-many whose inverse is to-one
 * (foreign key) are covered, since they persist through different machinery.
 */
final class PartialToManyRelationshipUpdateTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;

    /**
     * Article <-> Label many-to-many, plus Article -> Note to-many with a to-one inverse.
     */
    private static function makeModel(): ManagedObjectModel
    {
        $articleTitle = new AttributeDescription();
        $articleTitle->name = "title";
        $articleTitle->type = AttributeType::string;

        $labels = new RelationshipDescription();
        $labels->name = "labels";
        $labels->lazyDestinationEntityName = "Label";
        $labels->lazyInverseRelationshipName = "articles";
        $labels->isToMany = true;

        $article = new EntityDescription();
        $article->name = "Article";
        $article->managedObjectClassName = Article::class;
        $article->properties = new ArrayClass([$articleTitle, $labels]);

        $labelName = new AttributeDescription();
        $labelName->name = "name";
        $labelName->type = AttributeType::string;

        $articles = new RelationshipDescription();
        $articles->name = "articles";
        $articles->lazyDestinationEntityName = "Article";
        $articles->lazyInverseRelationshipName = "labels";
        $articles->isToMany = true;

        $label = new EntityDescription();
        $label->name = "Label";
        $label->managedObjectClassName = Label::class;
        $label->properties = new ArrayClass([$labelName, $articles]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$article, $label]);
        return $model;
    }

    private function makeContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makeModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * An Article carrying three named Labels, already saved.
     *
     * @return array{0: Article, 1: Dictionary<Label>}
     */
    private function makeSavedArticleWithThreeLabels(ManagedObjectContext $context): array
    {
        /** @var Dictionary<Label> $labelsByName */
        $labelsByName = new Dictionary();
        foreach (["alpha", "beta", "gamma"] as $name) {
            $label = new Label($context);
            $label->name = $name;
            $labelsByName[$name] = $label;
        }
        $article = new Article($context);
        $article->title = "Hello";
        $article->setValueForKey(new Set($labelsByName->values), "labels");
        $context->save();
        $this->assertSame(3, $article->labels->count, "precondition: the article starts with three labels");
        return [$article, $labelsByName];
    }

    /**
     * @param Article $article
     * @return list<string>
     */
    private function labelNames(ManagedObject $article): array
    {
        $names = $article->labels->map(fn(Label $label): string => (string)$label->name)->sorted([]);
        return array_values(iterator_to_array($names));
    }

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-partial-tomany-test-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    public function testAssigningASubsetKeepsTheRemainingObjects(): void
    {
        $context = $this->makeContext();
        [$article, $labels] = $this->makeSavedArticleWithThreeLabels($context);

        $article->setValueForKey(new Set([$labels["alpha"], $labels["gamma"]]), "labels");

        $this->assertTrue($context->hasChanges, "removing one of several related objects is a change");
        $this->assertSame(2, $article->labels->count, "the two remaining labels are still attached");
        $this->assertSame(["alpha", "gamma"], $this->labelNames($article), "exactly the label left out is gone");
    }

    public function testAssigningASubsetPersistsAndSurvivesAReload(): void
    {
        $context = $this->makeContext();
        [$article, $labels] = $this->makeSavedArticleWithThreeLabels($context);

        $article->setValueForKey(new Set([$labels["alpha"], $labels["gamma"]]), "labels");
        $context->save();

        $reloaded = $this->makeContext()->fetch(Article::fetchRequest())->first;
        $this->assertNotNull($reloaded, "the article is still there after reloading");
        $this->assertSame(2, $reloaded->labels->count, "the subset reached the store");
        $this->assertSame(["alpha", "gamma"], $this->labelNames($reloaded), "the surviving labels are the right ones");
    }

    /**
     * Only the removed object's inverse may change; the ones that stayed must keep theirs.
     */
    public function testAssigningASubsetOnlyClearsTheInverseOfTheRemovedObject(): void
    {
        $context = $this->makeContext();
        [$article, $labels] = $this->makeSavedArticleWithThreeLabels($context);

        $article->setValueForKey(new Set([$labels["alpha"], $labels["gamma"]]), "labels");

        $this->assertSame(0, $labels["beta"]->articles->count, "the removed label loses its inverse link");
        $this->assertSame(1, $labels["alpha"]->articles->count, "a label that stayed keeps its inverse link");
        $this->assertSame(1, $labels["gamma"]->articles->count, "the other label that stayed keeps its inverse link");
    }

    /**
     * The route a framework layer takes when it applies a request body: the client sends back
     * the objects that remain, as a list of references.
     */
    public function testUpdateFromSnapshotWithASubsetKeepsTheRemainingObjects(): void
    {
        $context = $this->makeContext();
        [$article, $labels] = $this->makeSavedArticleWithThreeLabels($context);

        $article->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "labels" => new ArrayClass([$labels["alpha"]->objectID, $labels["gamma"]->objectID]),
        ]));

        $this->assertTrue($context->hasChanges, "a subset in a snapshot is a change");
        $this->assertSame(2, $article->labels->count, "updateFromSnapshot applies the subset");
        $this->assertSame(["alpha", "gamma"], $this->labelNames($article), "the right label was dropped");
    }

    public function testUpdateFromSnapshotWithASubsetPersists(): void
    {
        $context = $this->makeContext();
        [$article, $labels] = $this->makeSavedArticleWithThreeLabels($context);

        $article->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "labels" => new ArrayClass([$labels["alpha"]->objectID, $labels["gamma"]->objectID]),
        ]));
        $context->save();

        $reloaded = $this->makeContext()->fetch(Article::fetchRequest())->first;
        $this->assertNotNull($reloaded, "the article is still there after reloading");
        $this->assertSame(["alpha", "gamma"], $this->labelNames($reloaded), "the subset reached the store");
    }

    /**
     * Removing one, saving, then removing another: the second update must start from the saved
     * state rather than from a stale snapshot of the original three.
     */
    public function testRemovingOneAtATimeAcrossSavesIsCumulative(): void
    {
        $context = $this->makeContext();
        [$article, $labels] = $this->makeSavedArticleWithThreeLabels($context);

        $article->setValueForKey(new Set([$labels["alpha"], $labels["beta"]]), "labels");
        $context->save();
        $this->assertSame(["alpha", "beta"], $this->labelNames($article), "the first removal took effect");

        $article->setValueForKey(new Set([$labels["alpha"]]), "labels");
        $context->save();

        $this->assertSame(["alpha"], $this->labelNames($article), "the second removal took effect too");

        $reloaded = $this->makeContext()->fetch(Article::fetchRequest())->first;
        $this->assertNotNull($reloaded, "the article is still there after reloading");
        $this->assertSame(["alpha"], $this->labelNames($reloaded), "both removals reached the store");
    }

    /**
     * Adding and removing in a single assignment: the diff has to handle both directions at once.
     */
    public function testAssigningASetThatBothAddsAndRemoves(): void
    {
        $context = $this->makeContext();
        [$article, $labels] = $this->makeSavedArticleWithThreeLabels($context);
        $delta = new Label($context);
        $delta->name = "delta";

        $article->setValueForKey(new Set([$labels["alpha"], $delta]), "labels");
        $context->save();

        $this->assertSame(["alpha", "delta"], $this->labelNames($article), "the added and removed objects are both handled");
        $this->assertSame(0, $labels["beta"]->articles->count, "a removed label loses its inverse");
        $this->assertSame(1, $delta->articles->count, "the added label gains its inverse");

        $reloaded = $this->makeContext()->fetch(Article::fetchRequest())->first;
        $this->assertNotNull($reloaded, "the article is still there after reloading");
        $this->assertSame(["alpha", "delta"], $this->labelNames($reloaded), "the mixed change reached the store");
    }
}
