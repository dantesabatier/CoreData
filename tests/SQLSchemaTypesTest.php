<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchIndexDescription;
use Sabatier\CoreData\FetchIndexElementDescription;
use Sabatier\CoreData\FetchIndexElementType;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SQLAliasGenerator;
use Sabatier\CoreData\SQLBinaryIndex;
use Sabatier\CoreData\SQLEntity;
use Sabatier\CoreData\SQLIndex;
use Sabatier\CoreData\SQLModel;
use Sabatier\CoreData\SQLRTreeIndex;
use Sabatier\CoreData\SQLStatement;
use Sabatier\CoreData\SQLToOne;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\InternalInconsistencyException;

/**
 * Covers the small schema-side types that had no suite of their own: the two specialised index
 * kinds, and the alias generator every SQL statement depends on for its table and variable names.
 *
 * These are asserted through the DDL they produce (SQLIndex exposes its statements) and through
 * the names the generator hands out, rather than by executing anything — the same approach
 * SQLGeneratorTest takes, and it needs no database.
 *
 * The index classes are reached the way the framework reaches them: SQLEntity picks the subclass
 * from the index element's collation type (SQLEntity.php:163-171), so the tests build a model
 * with the collation they want and read back the index SQLEntity constructed.
 */
final class SQLSchemaTypesTest extends TestCase
{
    private SQLModel $sqlModel;

    /**
     * A one-entity model whose "location" attribute carries an index of the given collation.
     * The entity also has a plain attribute so the default b-tree path stays exercised.
     *
     * The indexed attribute is explicitly NOT optional: AttributeDescription defaults isOptional
     * to true, and SQLRTreeIndex refuses to index an optional property (SQLRTreeIndex.php:15-19).
     * Leaving the default in place aborts the whole index dictionary mid-construction, and the
     * entity then falls back to the plain b-tree index — which reads as "the subclass was never
     * selected" rather than as the error it is.
     */
    private static function model(FetchIndexElementType $collationType): ManagedObjectModel
    {
        $location = new AttributeDescription();
        $location->name = "location";
        $location->type = AttributeType::string;
        $location->isOptional = false;

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = "Place";
        $entity->properties = new ArrayClass([$location, $label]);
        $entity->indexes = new ArrayClass([
            new FetchIndexDescription("place_location", new ArrayClass([
                new FetchIndexElementDescription($location, $collationType),
            ])),
        ]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    /**
     * Every DDL statement the index contributes to its table's creation, joined.
     *
     * Deliberately not just `->first`. A specialised index appends its own statement from its
     * constructor, but SQLIndex::$createTableStatements is a lazy hook guarded by isset() on the
     * very property being initialised — so on first read it does not see the subclass's append,
     * generates the generic b-tree statement, and lands it *before* the specialised one. Both
     * survive, in that order. Reading only the first statement therefore reports the generic DDL
     * for every index kind, which is what made these tests look like the subclass had not been
     * selected at all.
     */
    private static function createStatement(SQLIndex $index): string
    {
        return $index->createTableStatements->map(fn(SQLStatement $statement): string => $statement->string)->join("\n");
    }

    /** @noinspection PhpSameParameterValueInspection */
    private function index(string $name): SQLIndex
    {
        /** @var SQLEntity $entity */
        $entity = $this->sqlModel->entitiesByName["Place"];
        /** @var SQLIndex $index */
        $index = $entity->indexes[$name];
        return $index;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->sqlModel = new SQLModel(self::model(FetchIndexElementType::bTree), "default");
    }

    /**
     * An R-tree index element makes SQLEntity build an SQLRTreeIndex, and that subclass emits a
     * SPATIAL index rather than the b-tree default.
     */
    public function testSpatialCollationProducesASpatialIndex(): void
    {
        $this->sqlModel = new SQLModel(self::model(FetchIndexElementType::rTree), "default");

        $index = $this->index("place_location");

        $this->assertInstanceOf(SQLRTreeIndex::class, $index, "an rTree collation selects the spatial index class");
        $this->assertStringContainsString("SPATIAL INDEX", self::createStatement($index));
    }

    /**
     * A spatial index names its column WITHOUT a sort order. ADD SPATIAL INDEX rejects ASC/DESC
     * outright (MariaDB 1064), so the order FetchIndexElementDescription reports for an rTree
     * element must not reach the DDL — unlike the b-tree case, where it must.
     */
    public function testSpatialIndexNamesTheColumnWithoutASortOrder(): void
    {
        $this->sqlModel = new SQLModel(self::model(FetchIndexElementType::rTree), "default");

        $statement = self::createStatement($this->index("place_location"));

        $this->assertStringContainsString("(`location`)", $statement, "the indexed column is named bare");
        $this->assertStringNotContainsString("ASC", $statement, "a spatial index rejects a sort order on its column");
        $this->assertStringContainsString("`place_location`", $statement, "and the index is named after its description");
    }

    /**
     * A binary collation selects SQLBinaryIndex, whose DDL is a hash index — and unlike the
     * spatial one it emits no sort order, because a hash index has none to give.
     */
    public function testBinaryCollationProducesAHashIndex(): void
    {
        $this->sqlModel = new SQLModel(self::model(FetchIndexElementType::binary), "default");

        $index = $this->index("place_location");
        $statement = self::createStatement($index);

        $this->assertInstanceOf(SQLBinaryIndex::class, $index, "a binary collation selects the hash index class");
        $this->assertStringContainsString("USING HASH", $statement);
        $this->assertStringNotContainsString("`location` ASC", $statement, "a hash index carries no sort order for its column");
    }

    /**
     * A specialised index emits ONLY its own statement — never the generic b-tree one as well.
     *
     * It used to emit both: the subclass appended its DDL from its constructor, but
     * SQLIndex::$createTableStatements is a lazy hook whose isset() guard could not see that
     * append, so on first read it generated the generic statement and landed it *before* the
     * specialised one. The generic statement ran first and created a b-tree under the name the
     * model wanted for a HASH/SPATIAL index; the specialised statement that followed was not
     * even valid DDL (MariaDB 1064), so the whole batch failed once it reached a server.
     */
    public function testASpecialisedIndexEmitsOnlyItsOwnStatement(): void
    {
        foreach ([FetchIndexElementType::binary, FetchIndexElementType::rTree] as $collationType) {
            $this->sqlModel = new SQLModel(self::model($collationType), "default");

            $statements = $this->index("place_location")->createTableStatements;

            $this->assertSame(1, $statements->count, "a specialised index emits one statement, not its own plus the generic one");
            $this->assertStringNotContainsString("USING BTREE", $statements->first?->string ?? "", "and that statement is never the generic b-tree one");
        }
    }

    /**
     * Neither specialised kind may name itself with CONSTRAINT. MariaDB's grammar allows a
     * CONSTRAINT symbol only before UNIQUE, PRIMARY KEY and FOREIGN KEY — never before a plain
     * INDEX or a SPATIAL one — so the DDL these classes used to emit was a syntax error, and it
     * also left the index itself unnamed by putting the name in the symbol position.
     */
    public function testASpecialisedIndexIsNamedRatherThanConstrained(): void
    {
        foreach ([FetchIndexElementType::binary, FetchIndexElementType::rTree] as $collationType) {
            $this->sqlModel = new SQLModel(self::model($collationType), "default");

            $statement = self::createStatement($this->index("place_location"));

            $this->assertStringNotContainsString("ADD CONSTRAINT", $statement, "CONSTRAINT may not precede a plain or spatial INDEX");
            $this->assertStringContainsString("IF NOT EXISTS `place_location`", $statement, "the index carries its own name");
        }
    }

    /**
     * The default collation stays on the base class: neither specialised subclass should capture
     * an ordinary index, or every model would silently get spatial DDL.
     */
    public function testDefaultCollationStaysOnTheBaseIndex(): void
    {
        $index = $this->index("place_location");

        $this->assertNotInstanceOf(SQLRTreeIndex::class, $index);
        $this->assertNotInstanceOf(SQLBinaryIndex::class, $index);
        $this->assertStringContainsString("USING BTREE", self::createStatement($index));
    }

    /**
     * A spatial index refuses an optional property. MariaDB cannot build a SPATIAL index over a
     * nullable column, so the framework rejects the model rather than emitting DDL the server
     * would refuse — and it does so while building the entity's index dictionary, which is why
     * the failure surfaces on first access rather than at model construction.
     */
    public function testSpatialIndexRejectsAnOptionalProperty(): void
    {
        $location = new AttributeDescription();
        $location->name = "location";
        $location->type = AttributeType::string;
        $location->isOptional = true;

        $entity = new EntityDescription();
        $entity->name = "Place";
        $entity->properties = new ArrayClass([$location]);
        $entity->indexes = new ArrayClass([
            new FetchIndexDescription("place_location", new ArrayClass([
                new FetchIndexElementDescription($location, FetchIndexElementType::rTree),
            ])),
        ]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        $sqlModel = new SQLModel($model, "default");
        /** @var SQLEntity $sqlEntity */
        $sqlEntity = $sqlModel->entitiesByName["Place"];

        // The indexes are a lazy hook, so the rejection fires on first read, not at construction.
        // The result is asserted rather than discarded: a bare property read is a statement with
        // no effect as far as Rector is concerned, and it removes it — taking the test's only
        // action with it.
        $this->expectException(InternalInconsistencyException::class);
        $this->assertTrue($sqlEntity->indexes->isEmpty);
    }

    /**
     * Every entity gets an index on its entity key whatever it declares, so an index name is
     * only unique within the entity's own index dictionary — this pins that the declared index
     * and the implicit key index coexist rather than one displacing the other.
     */
    public function testTheEntityKeyIndexCoexistsWithADeclaredIndex(): void
    {
        /** @var SQLEntity $entity */
        $entity = $this->sqlModel->entitiesByName["Place"];

        $this->assertFalse($entity->indexes->isEmpty);
        $this->assertNotNull($entity->indexes["place_location"], "the declared index survives");
        $this->assertGreaterThan(1, $entity->indexes->count, "and the implicit entity-key index is there too");
    }

    // --- Foreign key columns of a to-one relationship ---

    /**
     * A Book -> Writer to-one relationship, with its to-many inverse, reached as SQLToOne.
     */
    private static function toOneRelationship(): SQLToOne
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $author = new RelationshipDescription();
        $author->name = "author";
        $author->lazyDestinationEntityName = "Writer";
        $author->lazyInverseRelationshipName = "books";

        $book = new EntityDescription();
        $book->name = "Book";
        $book->properties = new ArrayClass([$title, $author]);

        $writerName = new AttributeDescription();
        $writerName->name = "name";
        $writerName->type = AttributeType::string;

        $books = new RelationshipDescription();
        $books->name = "books";
        $books->lazyDestinationEntityName = "Book";
        $books->lazyInverseRelationshipName = "author";
        $books->isToMany = true;

        $writer = new EntityDescription();
        $writer->name = "Writer";
        $writer->properties = new ArrayClass([$writerName, $books]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$book, $writer]);

        /** @var SQLEntity $sqlBook */
        $sqlBook = new SQLModel($model, "default")->entitiesByName["Book"];
        /** @var SQLToOne $relationship */
        $relationship = $sqlBook->propertiesByName["author"];
        return $relationship;
    }

    /**
     * The foreign entity key names the destination entity and borrows the owning entity's own
     * entity-key column. It is what lets a polymorphic to-one record WHICH entity the row on the
     * other side belongs to, separately from which row it is.
     */
    public function testForeignEntityKeyNamesTheDestinationEntity(): void
    {
        $key = self::toOneRelationship()->foreignEntityKey;

        $this->assertSame("Writer", $key->name, "the column is named for the entity it points at");
        $this->assertSame("Book", $key->relationshipDescription->entity->name, "while belonging to the entity that declares the relationship");
    }

    /**
     * The foreign order key derives its column from the destination entity's first attribute,
     * falling back to the object ID when the entity declares none — it is the column a to-many
     * is ordered by when it is read back through its inverse.
     */
    public function testForeignOrderKeyDerivesItsColumnFromTheDestinationEntity(): void
    {
        $key = self::toOneRelationship()->foreignOrderKey;

        $this->assertSame("title", $key->columnName, "the first attribute of the ordered entity supplies the column");
    }

    /**
     * Both columns hang off the same foreign key, and both resolve back to the relationship they
     * were built for — the to-one is the single source both of them read.
     */
    public function testBothForeignColumnsShareTheRelationshipTheyWereBuiltFor(): void
    {
        $relationship = self::toOneRelationship();

        $this->assertSame($relationship, $relationship->foreignEntityKey->toOneRelationship);
        $this->assertSame($relationship, $relationship->foreignOrderKey->toOneRelationship);
    }

    // --- SQLAliasGenerator ---
    //
    // Every table alias in a generated statement comes from here. Uniqueness is the whole
    // contract: a repeated alias in a query with joins or a correlated subquery silently
    // resolves a column against the wrong table.

    public function testTableAliasesAreUniqueAndSequential(): void
    {
        $generator = new SQLAliasGenerator();

        $first = $generator->generateTableAlias();
        $second = $generator->generateTableAlias();

        $this->assertNotSame($first, $second, "two table aliases from one generator never collide");
        $this->assertSame("t1_0", $first, "aliases are the generator's base plus a counter");
        $this->assertSame("t1_1", $second);
    }

    public function testVariableAliasesAreUniqueAndSequential(): void
    {
        $generator = new SQLAliasGenerator();

        $this->assertNotSame($generator->generateVariableAlias(), $generator->generateVariableAlias());
    }

    public function testTempTableNamesAreUnique(): void
    {
        $generator = new SQLAliasGenerator();

        $this->assertNotSame($generator->generateTempTableName(), $generator->generateTempTableName());
    }

    /**
     * The nesting level is what keeps a subquery's aliases from colliding with its parent's.
     * Two generators at different levels must not produce the same name from the same counter
     * position, or a correlated subquery would shadow the outer table it correlates against.
     */
    public function testNestingLevelSeparatesAliasesBetweenGenerators(): void
    {
        $outer = new SQLAliasGenerator(1);
        $inner = new SQLAliasGenerator(2);

        $this->assertNotSame($outer->generateTableAlias(), $inner->generateTableAlias(), "the same counter position at another nesting level is a different alias");
        $this->assertNotSame($outer->generateTempTableName(), $inner->generateTempTableName());
        $this->assertNotSame($outer->generateVariableAlias(), $inner->generateVariableAlias());
    }

    /**
     * Subquery variable aliases come from the same counter as ordinary variable aliases, so
     * mixing the two calls must still never repeat a name.
     */
    public function testSubqueryAndOrdinaryVariableAliasesShareOneSequence(): void
    {
        $generator = new SQLAliasGenerator();

        $names = new ArrayClass([
            $generator->generateVariableAlias(),
            $generator->generateSubqueryVariableAlias(),
            $generator->generateVariableAlias(),
            $generator->generateSubqueryVariableAlias(),
        ]);

        $this->assertSame($names->count, new ArrayClass($names->array)->reduce(new ArrayClass(),
            /**
             * @param ArrayClass<string> $unique
             * @param string $name
             * @return ArrayClass<string>
             */
            static function (ArrayClass $unique, string $name): ArrayClass {
                if (!$unique->containsElement($name)) {
                    $unique->append($name);
                }
                return $unique;
            })->count, "interleaving the two variable-alias calls never repeats a name");
    }
}
