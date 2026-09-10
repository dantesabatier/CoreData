<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchedPropertyDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\QueryGenerationToken;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SQLCore;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Set;
use const Sabatier\CoreData\ManagedObjectQueryResultGenerationKey;

/**
 * @property Set<Novel> $books
 * @property string $name
 * @method void addBooksObject(Novel $object)
 * @method void removeBooksObject(Novel $object)
 * @method void addBooks(Set<Novel> $objects)
 * @method void removeBooks(Set<Novel> $objects)
 * @method Set<Novel> intersectBooks(Set<Novel> $objects)
 * @method void setBooks(Set<Novel> $objects)
 */
final class Novelist extends ManagedObject
{
}

/**
 * @property Novelist $author
 * @property string $title
 */
final class Novel extends ManagedObject
{
}

/**
 * Regression test for the query-generation token used when faulting a relationship (and a
 * fetched property) through {@see SQLCore}.
 *
 * The three faulting paths in SQLCore resolve the "expected" generation token the same way:
 * they honor the token the context is pinned to via ManagedObjectContext::setQueryGenerationFrom(),
 * falling back to a token built from the store's current generation only when the context is
 * NOT pinned. That token is written into the row cache alongside the faulted relationship, and
 * later read back to decide whether a cached fault is still compatible.
 *
 * A refactor removed the "value" wrapper property from QueryGenerationToken, but two of the
 * three paths kept dereferencing it as "$context->queryGenerationToken?->value". Because the
 * property no longer existed, that expression evaluated to null, so a pinned context was
 * ignored and every relationship/fetched-property fault was tagged with a freshly built
 * current-generation token instead. This test pins a context to a known token and asserts the
 * cached relationship snapshot carries THAT token — it fails on the pre-fix code (which stored
 * a current-generation token) and passes once the "?->value" is dropped.
 */
final class SQLCoreRelationshipFaultGenerationTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $books = new RelationshipDescription();
        $books->name = "books";
        $books->lazyDestinationEntityName = "Novel";
        $books->lazyInverseRelationshipName = "author";
        $books->isToMany = true;

        $author = new RelationshipDescription();
        $author->name = "author";
        $author->lazyDestinationEntityName = "Novelist";
        $author->lazyInverseRelationshipName = "books";
        $author->maxCount = 1;

        // A fetched property is a static, predicate-driven query. "$FETCH_SOURCE" is bound to the
        // owning Novelist at fault time, so this fetches every Novel whose author is this novelist.
        // It carries NO sort descriptors on purpose: SQLCore only caches (and thus only exercises
        // the corrected generation-token path) for the unsorted case.
        $novelsFetch = new FetchRequest("Novel");
        $novelsFetch->predicate = Predicate::format("author == \$FETCH_SOURCE");

        $recentNovels = new FetchedPropertyDescription();
        $recentNovels->name = "authoredNovels";
        $recentNovels->fetchRequest = $novelsFetch;

        $authorEntity = new EntityDescription();
        $authorEntity->name = "Novelist";
        $authorEntity->managedObjectClassName = Novelist::class;
        $authorEntity->properties = new ArrayClass([$name, $books, $recentNovels]);

        $bookEntity = new EntityDescription();
        $bookEntity->name = "Novel";
        $bookEntity->managedObjectClassName = Novel::class;
        $bookEntity->properties = new ArrayClass([$title, $author]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$authorEntity, $bookEntity]);
        return $model;
    }

    /**
     * Faulting a to-many relationship through a context pinned to a specific generation must
     * tag the cached relationship snapshot with that pinned token, not a freshly built one.
     */
    public function testRelationshipFaultHonorsThePinnedGenerationToken(): void
    {
        // Bootstrap a real SQL stack and insert an author with two books.
        $context = $this->bootstrap(self::model());
        $author = new Novelist($context);
        $author->name = "Le Guin";
        $first = new Novel($context);
        $first->title = "A Wizard of Earthsea";
        $first->setValueForKey($author, "author");
        $second = new Novel($context);
        $second->title = "The Tombs of Atuan";
        $second->setValueForKey($author, "author");
        $context->save();

        // Open a fresh stack (its own row cache) over the same database and fetch the author.
        $freshModel = self::model();
        $readContext = $this->freshContext($freshModel);
        /** @var SQLCore $store */
        $store = $readContext->persistentStoreCoordinator->persistentStores->first;

        $fetched = $readContext->fetch(Novelist::fetchRequest())->first;
        $this->assertNotNull($fetched, "precondition: the author round-trips through SQL");
        $objectID = $fetched->objectID;
        $booksRelationship = $freshModel->entitiesByName["Novelist"]->relationshipsByName["books"];

        // Discover the token the store fabricates for an un-pinned context, then pin the reading
        // context to one with the SAME store identifier and origin but a DIFFERENT generation.
        // This isolates the only field under test (generation) while guaranteeing the other two
        // fields match, so a failure can only mean the pinned token was ignored.
        $store->newValueForRelationship($booksRelationship, $objectID, $readContext);
        $fabricated = $store->rowCache->snapshot($objectID, $booksRelationship)[ManagedObjectQueryResultGenerationKey];
        $this->assertInstanceOf(QueryGenerationToken::class, $fabricated, "un-pinned fault tags the cache with a built token");

        $pinned = new QueryGenerationToken($fabricated->storeIdentifier, $fabricated->origin, $fabricated->generation + 7);
        $readContext->setQueryGenerationFrom($pinned);

        // Trigger the to-many relationship fault through the corrected SQLCore path.
        $store->newValueForRelationship($booksRelationship, $objectID, $readContext);

        // The cached relationship snapshot must carry the pinned token.
        $cached = $store->rowCache->snapshot($objectID, $booksRelationship);
        $this->assertNotNull($cached, "the relationship fault populated the row cache");
        /** @var QueryGenerationToken|null $storedToken */
        $storedToken = $cached[ManagedObjectQueryResultGenerationKey];
        $this->assertInstanceOf(QueryGenerationToken::class, $storedToken);
        $this->assertTrue(
            $pinned->isCompatible($storedToken),
            "the cached fault is tagged with the context's pinned generation token, not a freshly built one",
        );
    }

    /**
     * The complementary behavior: with a compatible cached fault in place, a second fault
     * through the same pinned context is served from cache and returns the same object IDs.
     */
    public function testCachedRelationshipFaultIsReusedUnderACompatibleToken(): void
    {
        $context = $this->bootstrap(self::model());
        $author = new Novelist($context);
        $author->name = "Butler";
        $book = new Novel($context);
        $book->title = "Kindred";
        $book->setValueForKey($author, "author");
        $context->save();

        $freshModel = self::model();
        $readContext = $this->freshContext($freshModel);
        /** @var SQLCore $store */
        $store = $readContext->persistentStoreCoordinator->persistentStores->first;

        $objectID = $readContext->fetch(Novelist::fetchRequest())->first->objectID;
        $booksRelationship = $freshModel->entitiesByName["Novelist"]->relationshipsByName["books"];

        // Prime the cache once (un-pinned) to learn the store's token, then pin to a compatible one.
        $store->newValueForRelationship($booksRelationship, $objectID, $readContext);
        $fabricated = $store->rowCache->snapshot($objectID, $booksRelationship)[ManagedObjectQueryResultGenerationKey];
        $pinned = new QueryGenerationToken($fabricated->storeIdentifier, $fabricated->origin, $fabricated->generation);
        $readContext->setQueryGenerationFrom($pinned);

        $firstFault = $store->newValueForRelationship($booksRelationship, $objectID, $readContext);
        $secondFault = $store->newValueForRelationship($booksRelationship, $objectID, $readContext);

        $ids = fn(ArrayClass $set): array => $set->map(fn($id): string => $id->uriRepresentation()->absoluteString)->array;
        $this->assertSame(
            $ids($firstFault),
            $ids($secondFault),
            "a second fault under the same pinned token is served from cache with identical IDs",
        );
    }

    /**
     * The third corrected path: faulting an (unsorted) fetched property must likewise honor the
     * context's pinned generation token when tagging its cached snapshot. This is the sibling of
     * testRelationshipFaultHonorsThePinnedGenerationToken for newValueForFetchedProperty(), which
     * had the identical "?->value" defect and was previously covered only indirectly.
     */
    public function testFetchedPropertyFaultHonorsThePinnedGenerationToken(): void
    {
        $context = $this->bootstrap(self::model());
        $author = new Novelist($context);
        $author->name = "Delany";
        $novel = new Novel($context);
        $novel->title = "Dhalgren";
        $novel->setValueForKey($author, "author");
        $context->save();

        $freshModel = self::model();
        $readContext = $this->freshContext($freshModel);
        /** @var SQLCore $store */
        $store = $readContext->persistentStoreCoordinator->persistentStores->first;

        $objectID = $readContext->fetch(Novelist::fetchRequest())->first->objectID;
        /** @var FetchedPropertyDescription $fetchedProperty */
        $fetchedProperty = $freshModel->entitiesByName["Novelist"]->propertiesByName["authoredNovels"];

        // Prime the cache un-pinned to learn the store's token, then pin to a DIFFERENT generation.
        $store->newValueForFetchedProperty($fetchedProperty, $objectID, $readContext);
        $fabricated = $store->rowCache->snapshot($objectID, $fetchedProperty)[ManagedObjectQueryResultGenerationKey];
        $this->assertInstanceOf(QueryGenerationToken::class, $fabricated, "un-pinned fetched-property fault caches a built token");

        $pinned = new QueryGenerationToken($fabricated->storeIdentifier, $fabricated->origin, $fabricated->generation + 5);
        $readContext->setQueryGenerationFrom($pinned);

        $store->newValueForFetchedProperty($fetchedProperty, $objectID, $readContext);

        $cached = $store->rowCache->snapshot($objectID, $fetchedProperty);
        $this->assertNotNull($cached, "the fetched-property fault populated the row cache");
        /** @var QueryGenerationToken|null $storedToken */
        $storedToken = $cached[ManagedObjectQueryResultGenerationKey];
        $this->assertInstanceOf(QueryGenerationToken::class, $storedToken);
        $this->assertTrue(
            $pinned->isCompatible($storedToken),
            "the cached fetched-property fault is tagged with the context's pinned generation token",
        );
    }
}
