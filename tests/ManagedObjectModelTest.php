<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DeleteRule;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

final class Author extends ManagedObject
{
}

final class Book extends ManagedObject
{
}

/**
 * Tests for the schema layer: src/ManagedObjectModel.php, src/EntityDescription.php,
 * src/AttributeDescription.php and src/RelationshipDescription.php.
 *
 * Behavior pinned down here:
 *  - assigning EntityDescription::$properties clones/adopts the property
 *    descriptions, keys them by name, and freezes them;
 *  - attributesByName/relationshipsByName partition propertiesByName by kind;
 *  - RelationshipDescription resolves destinationEntity and inverseRelationship
 *    lazily from the model, after the model has been assembled;
 *  - AttributeDescription::$type and RelationshipDescription::$deleteRule accept
 *    raw ints and coerce them into their enums;
 *  - attributeValueClassName maps date/uuid/uri/objectID types to Foundation classes;
 *  - assigning ManagedObjectModel::$entities registers each entity's
 *    managedObjectClassName so the subclass' static entity()/fetchRequest() work;
 *  - versionHash is stable for identically-shaped entities and sensitive to
 *    persistence-affecting changes.
 */
final class ManagedObjectModelTest extends TestCase
{
    private ManagedObjectModel $model;
    private EntityDescription $author;
    private EntityDescription $book;

    /** Builds the two-entity Author <-> Book model used throughout this file. */
    private static function makeLibraryModel(): ManagedObjectModel
    {
        $authorName = new AttributeDescription();
        $authorName->name = "name";
        $authorName->type = AttributeType::string;
        $authorName->isOptional = false;

        $books = new RelationshipDescription();
        $books->name = "books";
        $books->lazyDestinationEntityName = "Book";
        $books->lazyInverseRelationshipName = "author";
        $books->isToMany = true;
        $books->deleteRule = DeleteRule::cascadeDeleteRule;

        $author = new EntityDescription();
        $author->name = "Author";
        $author->managedObjectClassName = Author::class;
        $author->properties = new ArrayClass([$authorName, $books]);

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $pages = new AttributeDescription();
        $pages->name = "pages";
        $pages->type = AttributeType::integer32;
        $pages->defaultValue = 100;

        $authorRelationship = new RelationshipDescription();
        $authorRelationship->name = "author";
        $authorRelationship->lazyDestinationEntityName = "Author";
        $authorRelationship->lazyInverseRelationshipName = "books";
        $authorRelationship->maxCount = 1;

        $book = new EntityDescription();
        $book->name = "Book";
        $book->managedObjectClassName = Book::class;
        $book->properties = new ArrayClass([$title, $pages, $authorRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$author, $book]);
        return $model;
    }

    protected function setUp(): void
    {
        $this->model = self::makeLibraryModel();
        $this->author = $this->model->entitiesByName["Author"];
        $this->book = $this->model->entitiesByName["Book"];
    }

    public function testModelAssembly(): void
    {
        $this->assertCount(2, $this->model->entities, "the model holds both entities");
        $this->assertSame("Author", $this->author->name, "entitiesByName resolves Author");
        $this->assertSame("Book", $this->book->name, "entitiesByName resolves Book");
        $this->assertSame($this->model, $this->author->managedObjectModel, "the entity is associated back to the model");
    }

    public function testEntityPropertiesArePartitionedByKind(): void
    {
        $this->assertCount(3, $this->book->properties, "Book keeps its three property descriptions");
        $this->assertInstanceOf(AttributeDescription::class, $this->book->propertiesByName["title"]);
        $this->assertInstanceOf(RelationshipDescription::class, $this->book->propertiesByName["author"]);
        $this->assertSame($this->book, $this->book->propertiesByName["title"]->entity, "adopted properties point back to their entity");
        $this->assertFalse($this->book->propertiesByName["title"]->isEditable, "adopted properties are frozen");

        $this->assertCount(2, $this->book->attributesByName, "attributesByName contains only the attributes");
        $this->assertInstanceOf(AttributeDescription::class, $this->book->attributesByName["pages"]);
        $this->assertFalse($this->book->attributesByName->offsetExists("author"), "attributesByName does not contain relationships");

        $this->assertCount(1, $this->book->relationshipsByName, "relationshipsByName contains only the relationships");
        $this->assertInstanceOf(RelationshipDescription::class, $this->book->relationshipsByName["author"]);
    }

    public function testAttributeDescriptionKeepsItsConfiguration(): void
    {
        $pages = $this->book->attributesByName["pages"];

        $this->assertSame(AttributeType::integer32, $pages->type, "the attribute keeps its declared type");
        $this->assertSame(100, $pages->defaultValue, "the attribute keeps its default value");
        $this->assertTrue($this->book->attributesByName["title"]->isOptional, "attributes are optional by default");
        $this->assertFalse($this->author->attributesByName["name"]->isOptional, "isOptional false is kept");
    }

    public function testAttributeTypeAcceptsRawInts(): void
    {
        $coerced = new AttributeDescription();
        $coerced->name = "raw";
        $coerced->type = AttributeType::string->value;

        $this->assertSame(AttributeType::string, $coerced->type, "a raw int assigned to type is coerced into the AttributeType enum");
    }

    public function testAttributeValueClassNameMapsTypedAttributesToFoundationClasses(): void
    {
        foreach ([[AttributeType::date, Date::class], [AttributeType::uuid, UUID::class], [AttributeType::uri, URL::class], [AttributeType::objectID, ManagedObjectID::class]] as [$type, $class]) {
            $typed = new AttributeDescription();
            $typed->name = "typed";
            $typed->type = $type;
            $this->assertSame($class, $typed->attributeValueClassName, "attributeValueClassName maps {$type->name} to {$class}");
        }

        $untyped = new AttributeDescription();
        $untyped->name = "untyped";
        $untyped->type = AttributeType::string;
        $this->assertNull($untyped->attributeValueClassName, "attributeValueClassName is null for scalar types");
    }

    public function testRelationshipsResolveDestinationAndInverseFromTheModel(): void
    {
        $books = $this->author->relationshipsByName["books"];
        $authorRelationship = $this->book->relationshipsByName["author"];

        $this->assertSame($this->book, $books->destinationEntity, "the to-many side resolves its destination entity from the model");
        $this->assertSame($this->author, $authorRelationship->destinationEntity, "the to-one side resolves its destination entity from the model");
        $this->assertSame($authorRelationship, $books->inverseRelationship, "the to-many side resolves its inverse relationship");
        $this->assertSame($books, $authorRelationship->inverseRelationship, "the to-one side resolves its inverse relationship");
        $this->assertTrue($books->isToMany, "isToMany is kept on the to-many side");
        $this->assertFalse($authorRelationship->isToMany, "isToMany defaults to false on the to-one side");
        $this->assertSame(DeleteRule::cascadeDeleteRule, $books->deleteRule, "the delete rule is kept");
        $this->assertSame(DeleteRule::nullifyDeleteRule, $authorRelationship->deleteRule, "the delete rule defaults to nullify");
    }

    public function testDeleteRuleAcceptsRawInts(): void
    {
        $coercedRule = new RelationshipDescription();
        $coercedRule->name = "raw";
        $coercedRule->deleteRule = DeleteRule::denyDeleteRule->value;

        $this->assertSame(DeleteRule::denyDeleteRule, $coercedRule->deleteRule, "a raw int assigned to deleteRule is coerced into the DeleteRule enum");
    }

    public function testAssemblingTheModelRegistersTheManagedObjectSubclasses(): void
    {
        $this->assertSame($this->author, Author::entity(), "assembling the model registers the entity on its ManagedObject subclass");
        $this->assertSame($this->book, Book::entity(), "every entity with a managedObjectClassName is registered");
        $this->assertSame($this->book, Book::fetchRequest()->entity, "the subclass fetchRequest() is pre-configured with its entity");
    }

    public function testVersionHashesAreDeterministicAndShapeSensitive(): void
    {
        $rebuilt = self::makeLibraryModel();

        $this->assertSame($rebuilt->entitiesByName["Author"]->versionHash, $this->author->versionHash, "identically-shaped entities produce the same version hash");
        $this->assertSame($rebuilt->entitiesByName["Book"]->versionHash, $this->book->versionHash, "the hash is deterministic across model builds");
        $this->assertNotSame($this->book->versionHash, $this->author->versionHash, "differently-shaped entities produce different version hashes");
    }
}
