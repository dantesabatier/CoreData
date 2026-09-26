<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Set;

/**
 * @property string $name
 * @property Set<SCBook>|null $books
 * @method void addBooksObject(SCBook $object)
 * @method void removeBooksObject(SCBook $object)
 * @method void addBooks(Set<SCBook> $objects)
 * @method void removeBooks(Set<SCBook> $objects)
 * @method Set<SCBook> intersectBooks(Set<SCBook> $objects)
 * @method void setBooks(Set<SCBook> $objects)
 */
final class SCAuthor extends ManagedObject
{
}

/**
 * @property string $title
 * @property SCAuthor|null $author
 */
final class SCBook extends ManagedObject
{
}

/**
 * A shape that walks both sides of one relationship — the author's books, and each book's author.
 *
 * The preparer survives this, but serialization must also omit the inverse relationship when it emits a
 * related object. Otherwise, the author emits its books and each book emits the author again.
 */
final class SerializationCycleTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $books = new RelationshipDescription();
        $books->name = "books";
        $books->lazyDestinationEntityName = "SCBook";
        $books->lazyInverseRelationshipName = "author";
        $books->isToMany = true;
        $books->isOptional = true;

        $author = new EntityDescription();
        $author->name = "SCAuthor";
        $author->managedObjectClassName = SCAuthor::class;
        $author->properties = new ArrayClass([$name, $books]);

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $bookAuthor = new RelationshipDescription();
        $bookAuthor->name = "author";
        $bookAuthor->lazyDestinationEntityName = "SCAuthor";
        $bookAuthor->lazyInverseRelationshipName = "books";
        $bookAuthor->isOptional = true;

        $book = new EntityDescription();
        $book->name = "SCBook";
        $book->managedObjectClassName = SCBook::class;
        $book->properties = new ArrayClass([$title, $bookAuthor]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$author, $book]);
        return $model;
    }

    /** @throws Exception */
    private function seed(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::model());
        $author = new SCAuthor($context);
        $author->name = "Ursula K. Le Guin";

        $book = new SCBook($context);
        $book->title = "The Left Hand of Darkness";
        $book->author = $author;

        $context->save();
        return $context;
    }

    /** @throws Exception */
    public function testAShapeThatWalksBothSidesOfARelationshipTerminates(): void
    {
        $context = $this->seed();
        $shape = Dictionary::dictionaryWithArray([
            "name" => AttributeType::string,
            "books" => [
                "title" => AttributeType::string,
                "author" => [
                    "name" => AttributeType::string,
                ],
            ],
        ]);

        $body = null;
        $context->performBlockAndWait(function () use ($context, $shape, &$body): void {
            $request = new FetchRequest("SCAuthor");
            $request->serialization = $shape;
            $body = $context->fetch($request)->first->jsonSerialize();
        });

        self::assertSame("Ursula K. Le Guin", $body["name"]);
        self::assertSame("The Left Hand of Darkness", $body["books"][0]["title"]);
        self::assertArrayNotHasKey("author", $body["books"][0]);
    }
}
