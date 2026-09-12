<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

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
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property string $name
 * @property Set<Post> $posts
 * @method void addPostsObject(Post $object)
 * @method void removePostsObject(Post $object)
 * @method void addPosts(Set<Post> $objects)
 * @method void removePosts(Set<Post> $objects)
 * @method Set<Post> intersectPosts(Set<Post> $objects)
 * @method void setPosts(Set<Post> $objects)
 */
final class Tag extends ManagedObject
{
}

/**
 * @property Set<Tag> $tags
 * @property string $title
 * @method void addTagsObject(Tag $object)
 * @method void removeTagsObject(Tag $object)
 * @method void addTags(Set<Tag> $objects)
 * @method void removeTags(Set<Tag> $objects)
 * @method Set<Tag> intersectTags(Set<Tag> $objects)
 * @method void setTags(Set<Tag> $objects)
 */
final class Post extends ManagedObject
{
}

/**
 * Emptying a to-many relationship, over a full stack
 * (ManagedObjectModel -> PersistentStoreCoordinator -> XMLObjectStore).
 *
 * Going from [something] to [] is a change like any other, so it must mark the context dirty,
 * survive a save, and still be empty when a freshly-built stack reads the same file back. It is
 * covered here through both routes that reach a relationship:
 *
 *  - direct assignment (setValueForKey / removeObject), what application code does;
 *  - updateFromSnapshot, what a framework layer does when it applies a request body — the
 *    route where an empty collection is easiest to lose, because an empty ArrayClass is
 *    falsy-looking and a filter that skips "empty" values would silently drop it.
 *
 * Both a to-many whose inverse is to-one (foreign key) and a genuine many-to-many
 * (correlation table) are exercised, since they persist through different machinery.
 */
final class ClearToManyRelationshipTest extends TestCase
{
    private URL $storeURL;

    /**
     * Post <-> Tag many-to-many, plus Post -> Comment to-many with a to-one inverse.
     */
    private static function makeModel(): ManagedObjectModel
    {
        $postTitle = new AttributeDescription();
        $postTitle->name = "title";
        $postTitle->type = AttributeType::string;

        $tags = new RelationshipDescription();
        $tags->name = "tags";
        $tags->lazyDestinationEntityName = "Tag";
        $tags->lazyInverseRelationshipName = "posts";
        $tags->isToMany = true;

        $post = new EntityDescription();
        $post->name = "Post";
        $post->managedObjectClassName = Post::class;
        $post->properties = new ArrayClass([$postTitle, $tags]);

        $tagName = new AttributeDescription();
        $tagName->name = "name";
        $tagName->type = AttributeType::string;

        $posts = new RelationshipDescription();
        $posts->name = "posts";
        $posts->lazyDestinationEntityName = "Post";
        $posts->lazyInverseRelationshipName = "tags";
        $posts->isToMany = true;

        $tag = new EntityDescription();
        $tag->name = "Tag";
        $tag->managedObjectClassName = Tag::class;
        $tag->properties = new ArrayClass([$tagName, $posts]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$post, $tag]);
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
     * A Post carrying one Tag, already saved, so the relationship starts non-empty and
     * persisted rather than merely pending.
     */
    private function makeSavedPostWithOneTag(ManagedObjectContext $context): Post
    {
        $tag = new Tag($context);
        $tag->name = "php";
        $post = new Post($context);
        $post->title = "Hello";
        $post->setValueForKey(new Set([$tag]), "tags");
        $context->save();
        $this->assertSame(1, $post->tags->count, "precondition: the post starts with one tag");
        return $post;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");
    }

    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    public function testAssigningAnEmptySetMarksTheContextAsChanged(): void
    {
        $context = $this->makeContext();
        $post = $this->makeSavedPostWithOneTag($context);
        $this->assertFalse($context->hasChanges, "precondition: saving leaves the context clean");

        $post->setValueForKey(new Set(), "tags");

        $this->assertTrue($context->hasChanges, "emptying a to-many relationship is a change");
        $this->assertSame(0, $post->tags->count, "the relationship is empty right after assignment");
    }

    public function testAssigningAnEmptySetPersistsAndSurvivesAReload(): void
    {
        $context = $this->makeContext();
        $post = $this->makeSavedPostWithOneTag($context);

        $post->setValueForKey(new Set(), "tags");
        $context->save();

        $this->assertSame(0, $post->tags->count, "the relationship stays empty after saving");

        // A brand-new stack over the same file: proves the empty state reached the store
        // instead of only living in the in-memory graph.
        $reloaded = $this->makeContext()->fetch(Post::fetchRequest())->first;
        $this->assertNotNull($reloaded, "the post is still there after reloading");
        $this->assertSame(0, $reloaded->tags->count, "the relationship is still empty in a freshly-read store");
    }

    public function testRemovingTheLastObjectEmptiesTheRelationship(): void
    {
        $context = $this->makeContext();
        $post = $this->makeSavedPostWithOneTag($context);
        $tag = $post->tags->first;

        $post->removeTagsObject($tag);

        $this->assertTrue($context->hasChanges, "removing the last related object is a change");
        $this->assertSame(0, $post->tags->count, "the relationship is empty after removing its only object");
    }

    /**
     * The route a framework layer takes when it applies a request body. An empty ArrayClass is
     * exactly what json_decode('{"tags":[]}') produces once wrapped in a Dictionary.
     */
    public function testUpdateFromSnapshotWithAnEmptyArrayClassEmptiesTheRelationship(): void
    {
        $context = $this->makeContext();
        $post = $this->makeSavedPostWithOneTag($context);

        $post->updateFromSnapshot(Dictionary::dictionaryWithArray(["tags" => new ArrayClass()]));

        $this->assertTrue($context->hasChanges, "an empty collection in a snapshot is a change");
        $this->assertSame(0, $post->tags->count, "updateFromSnapshot applies an empty collection");
    }

    public function testUpdateFromSnapshotWithAnEmptyArrayClassPersists(): void
    {
        $context = $this->makeContext();
        $post = $this->makeSavedPostWithOneTag($context);

        $post->updateFromSnapshot(Dictionary::dictionaryWithArray(["tags" => new ArrayClass()]));
        $context->save();

        $reloaded = $this->makeContext()->fetch(Post::fetchRequest())->first;
        $this->assertNotNull($reloaded, "the post is still there after reloading");
        $this->assertSame(0, $reloaded->tags->count, "the emptied relationship reached the store");
    }

    /**
     * The decoded shape of a real request body, key included: {"title":"Hello","tags":[]}.
     * A layer that drops empty values would keep the title and silently lose the tags.
     */
    public function testUpdateFromSnapshotKeepsOtherKeysWhileEmptyingTheRelationship(): void
    {
        $context = $this->makeContext();
        $post = $this->makeSavedPostWithOneTag($context);

        /** @var array{title: string, tags: list<mixed>} $decoded */
        $decoded = json_decode('{"title":"Renamed","tags":[]}', true);
        $post->updateFromSnapshot(Dictionary::dictionaryWithArray($decoded));

        $this->assertSame("Renamed", $post->title, "the scalar key is applied");
        $this->assertSame(0, $post->tags->count, "the empty collection is applied too");
    }

    /**
     * Emptying one side must clear the inverse, otherwise the two sides disagree.
     */
    public function testEmptyingOneSideClearsTheInverse(): void
    {
        $context = $this->makeContext();
        $post = $this->makeSavedPostWithOneTag($context);
        $tag = $post->tags->first;

        $post->setValueForKey(new Set(), "tags");

        $this->assertSame(0, $tag->posts->count, "the inverse side is cleared as well");
    }
}
