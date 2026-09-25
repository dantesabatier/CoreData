<?php

/** @noinspection SqlDialectInspection, SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PDO;
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
use Sabatier\CoreData\SQLAdapter;
use Sabatier\CoreData\SQLColumn;
use Sabatier\CoreData\SQLCore;
use Sabatier\CoreData\SQLEntity;
use Sabatier\CoreData\SQLManyToMany;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ProcessInfo;
use Sabatier\Foundation\SearchMethod;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use function Sabatier\Foundation\string_search;

/** @property string $sku */
final class AdapterPart extends ManagedObject
{
}

/** @property string $name */
final class AdapterTag extends ManagedObject
{
}

/**
 * Characterization tests for SQLAdapter, which turns model columns into the DDL that builds and
 * alters the schema.
 *
 * It is the migrator's vocabulary: every ALTER, every CREATE INDEX and every RENAME the store
 * emits comes from here. A wrong clause fails against the server rather than silently, but the
 * column TYPES are the dangerous part — a column declared without UNSIGNED, or a string given
 * the wrong length, is accepted by MariaDB and truncates data much later.
 *
 * Asserted as strings, without executing anything: the adapter builds statements, it does not
 * run them. A live SQLCore is still needed because the adapter reads table and column names off
 * the SQLModel, and the CREATE TABLE forms read the connection's schema for engine and charset.
 */
final class SQLAdapterTest extends TestCase
{
    /** @var list<ManagedObjectContext> Every context built by a test, released in tearDown. */
    private array $contexts = [];

    private SQLCore $store;
    private SQLAdapter $adapter;

    /**
     * A Part/Tag model: one required string, one optional bounded integer, a to-one and its
     * to-many inverse, plus a many-to-many so the correlation-table statements are reachable.
     */
    private static function model(): ManagedObjectModel
    {
        $sku = new AttributeDescription();
        $sku->name = "sku";
        $sku->type = AttributeType::string;
        $sku->isOptional = false;

        $qty = new AttributeDescription();
        $qty->name = "qty";
        $qty->type = AttributeType::integer32;
        $qty->isOptional = true;
        $qty->minValue = 0;

        $tags = new RelationshipDescription();
        $tags->name = "tags";
        $tags->lazyDestinationEntityName = "AdapterTag";
        $tags->lazyInverseRelationshipName = "parts";
        $tags->isToMany = true;

        $part = new EntityDescription();
        $part->name = "AdapterPart";
        $part->managedObjectClassName = AdapterPart::class;
        $part->properties = new ArrayClass([$sku, $qty, $tags]);

        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $parts = new RelationshipDescription();
        $parts->name = "parts";
        $parts->lazyDestinationEntityName = "AdapterPart";
        $parts->lazyInverseRelationshipName = "tags";
        $parts->isToMany = true;

        $tag = new EntityDescription();
        $tag->name = "AdapterTag";
        $tag->managedObjectClassName = AdapterTag::class;
        $tag->properties = new ArrayClass([$name, $parts]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$part, $tag]);
        return $model;
    }

    /** @throws Exception */
    private function entity(string $name = "AdapterPart"): SQLEntity
    {
        /** @var SQLEntity $entity */
        $entity = $this->store->model->entitiesByName[$name];
        return $entity;
    }

    /** @throws Exception */
    private function column(string $columnName): SQLColumn
    {
        /** @var SQLColumn $column */
        $column = $this->entity()->propertiesByName[$columnName];
        return $column;
    }

    /** The many-to-many between the two entities, from the Part side. @throws Exception */
    private function manyToMany(): SQLManyToMany
    {
        $relationship = $this->entity()->propertiesByName["tags"];
        $this->assertInstanceOf(SQLManyToMany::class, $relationship, "the fixture's to-many has a to-many inverse");
        return $relationship;
    }

    /** @throws Exception */
    #[Override]
    protected function setUp(): void
    {
        $schema = ProcessInfo::processInfo()->environment["SQL_SCHEMA_NAME"] ?? "coredata_migration_test";
        new PDO("mysql:host=127.0.0.1", "root", null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
            ->exec("CREATE DATABASE IF NOT EXISTS `$schema`");
        $coordinator = new PersistentStoreCoordinator(self::model());
        $store = $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, new URL("sql://$schema"));
        self::assertInstanceOf(SQLCore::class, $store);
        $this->store = $store;
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->contexts[] = $context;
        $this->adapter = $store->adapter;
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->contexts as $context) {
            $context->persistentStoreCoordinator = null;
        }
        $this->contexts = [];
    }

    // --- Column types ---

    /**
     * The primary key is an unsigned auto-increment. Every one of those words matters: signed
     * would halve the row space, and without AUTO_INCREMENT the store would have to supply keys
     * it does not generate.
     *
     * @throws Exception
     */
    public function testThePrimaryKeyIsUnsignedAndAutoIncrementing(): void
    {
        $statement = $this->adapter->newCreateColumnStatement($this->entity()->primaryKey);

        $this->assertStringContainsString("UNSIGNED NOT NULL AUTO_INCREMENT", $statement?->string ?? "");
    }

    /**
     * A required attribute is NOT NULL; an optional one is not. This is the constraint the model
     * declares, and the column is where it is actually enforced.
     *
     * @throws Exception
     */
    public function testRequiredAndOptionalAttributesDifferInNullability(): void
    {
        $required = $this->adapter->newCreateColumnStatement($this->column("sku"))?->string ?? "";
        $optional = $this->adapter->newCreateColumnStatement($this->column("qty"))?->string ?? "";

        $this->assertStringContainsString("NOT NULL", $required, "a required attribute is NOT NULL");
        $this->assertStringNotContainsString("NOT NULL", $optional, "an optional one is not");
    }

    /**
     * An integer whose minimum is zero is declared UNSIGNED, which doubles its positive range
     * rather than leaving half the column unusable.
     *
     * @throws Exception
     */
    public function testANonNegativeIntegerIsUnsigned(): void
    {
        $statement = $this->adapter->newCreateColumnStatement($this->column("qty"));

        $this->assertStringContainsString("UNSIGNED", $statement?->string ?? "");
    }

    /**
     * A string carries an explicit length. Without one MariaDB would refuse the column outright,
     * and the default of 255 is what an attribute with no declared maximum gets.
     *
     * @throws Exception
     */
    public function testAStringColumnCarriesALength(): void
    {
        $statement = $this->adapter->newCreateColumnStatement($this->column("sku"));

        $this->assertMatchesRegularExpression("/`sku` \w+\(\d+\)/", $statement?->string ?? "");
    }

    /**
     * The entity key names the table it belongs to as its default, which is how a row reports
     * which entity it is when several share one table.
     *
     * @throws Exception
     */
    public function testTheEntityKeyDefaultsToTheTableName(): void
    {
        $statement = $this->adapter->newCreateColumnStatement($this->entity()->entityKey);

        $this->assertStringContainsString("DEFAULT 'AdapterPart'", $statement?->string ?? "");
    }

    // --- Column statements ---

    /**
     * Creating a column without a position appends it; with one it is placed after that column.
     * Position matters to the migrator, which rebuilds a table's column order.
     *
     * @throws Exception
     */
    public function testCreatingAColumnCanPlaceItAfterAnother(): void
    {
        $plain = $this->adapter->newCreateColumnStatement($this->column("qty"))?->string ?? "";
        $placed = $this->adapter->newCreateColumnStatement($this->column("qty"), $this->column("sku"))?->string ?? "";

        $this->assertStringContainsString("ADD COLUMN IF NOT EXISTS", $plain);
        $this->assertStringNotContainsString("AFTER", $plain, "with no anchor the column is appended");
        $this->assertStringContainsString("AFTER `sku`", $placed, "with an anchor it is placed after it");
    }

    /**
     * Dropping a column is guarded by IF EXISTS: a migration re-run against a schema that was
     * already migrated must not fail on a column that is already gone.
     *
     * @throws Exception
     */
    public function testDroppingAColumnToleratesItsAbsence(): void
    {
        $statement = $this->adapter->newDropColumnStatement($this->column("qty"));

        $this->assertStringContainsString("DROP COLUMN IF EXISTS `qty`", $statement->string);
    }

    /**
     * Renaming a column to a different name emits a RENAME rather than a MODIFY — the column
     * keeps its data and only its name changes.
     *
     * @throws Exception
     */
    public function testRenamingToADifferentNameEmitsARename(): void
    {
        $statement = $this->adapter->newRenameColumnStatement($this->column("sku"), $this->column("qty"));

        $this->assertStringContainsString("RENAME COLUMN IF EXISTS `sku` TO `qty`", $statement?->string ?? "");
    }

    /**
     * Renaming a column to its own name is not a rename at all: it is how the migrator asks for
     * a type change, so it emits a MODIFY carrying the new type.
     *
     * @throws Exception
     */
    public function testRenamingToTheSameNameModifiesTheTypeInstead(): void
    {
        $column = $this->column("sku");

        $statement = $this->adapter->newRenameColumnStatement($column, $column);

        $this->assertStringContainsString("MODIFY IF EXISTS", $statement?->string ?? "");
        $this->assertStringNotContainsString("RENAME", $statement?->string ?? "");
    }

    /**
     * Modifying a column places it after a named one, which is how the migrator preserves
     * column order while changing a type.
     *
     * @throws Exception
     */
    public function testModifyingAColumnPlacesItAfterAnother(): void
    {
        $statement = $this->adapter->newModifyColumnStatement($this->column("qty"), $this->column("sku"));

        $this->assertStringContainsString("MODIFY IF EXISTS", $statement?->string ?? "");
        $this->assertStringContainsString("AFTER `sku`", $statement?->string ?? "");
    }

    // --- Table statements ---

    /**
     * A create statement declares the primary key constraint and the engine, charset and
     * collation the connection is configured with — a table created with the server's defaults
     * instead would collate differently from every other table in the store.
     *
     * @throws Exception
     */
    public function testCreatingATableDeclaresItsKeyAndItsCollation(): void
    {
        $statement = $this->adapter->newCreateTableStatement($this->entity());

        $this->assertStringContainsString(/** @lang text */ "CREATE TABLE IF NOT EXISTS `AdapterPart`",$statement->string);
        $this->assertStringContainsString("PRIMARY KEY", $statement->string);
        $this->assertStringContainsString("ENGINE=", $statement->string);
        $this->assertStringContainsString("COLLATE=", $statement->string);
    }

    /** @throws Exception */
    public function testDroppingATableToleratesItsAbsence(): void
    {
        $this->assertStringContainsString(
            "DROP TABLE IF EXISTS `AdapterPart`",
            $this->adapter->newDropTableStatement($this->entity())->string,
        );
    }

    /** @throws Exception */
    public function testRenamingATableNamesBothEnds(): void
    {
        $statement = $this->adapter->newRenameTableStatement($this->entity(), $this->entity("AdapterTag"));

        $this->assertStringContainsString("RENAME TABLE IF EXISTS `AdapterPart` TO `AdapterTag`", $statement->string);
    }

    /**
     * Renaming tables across databases is how a store is replaced: the tables move from the
     * staging database to the live one, each qualified by its own database name.
     */
    public function testRenamingTablesAcrossDatabasesQualifiesBothSides(): void
    {
        $statement = $this->adapter->newRenameTablesStatement("old", "new", new Set(["Alpha", "Beta"]));

        $this->assertStringContainsString("`old`.`Alpha` TO `new`.`Alpha`", $statement?->string ?? "");
        $this->assertStringContainsString("`old`.`Beta` TO `new`.`Beta`", $statement?->string ?? "");
    }

    /**
     * Renaming no tables is not an empty RENAME — which is a syntax error — but no statement at
     * all, so the caller skips the step.
     */
    public function testRenamingNoTablesYieldsNoStatement(): void
    {
        $this->assertNull($this->adapter->newRenameTablesStatement("old", "new", new Set()));
    }

    public function testDroppingADatabaseToleratesItsAbsence(): void
    {
        $this->assertStringContainsString(
            "DROP DATABASE IF EXISTS `scratch`",
            $this->adapter->newDropDatabaseStatement("scratch")->string,
        );
    }

    // --- Many-to-many correlation tables ---

    /**
     * The correlation table holds the two sides' keys and nothing else, with both as its
     * composite primary key — that pair is what makes a membership unique.
     *
     * @throws Exception
     */
    public function testTheCorrelationTableIsKeyedByBothSides(): void
    {
        $manyToMany = $this->manyToMany();

        $statement = $this->adapter->newCreateTableStatementForManyToMany($manyToMany);

        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS `$manyToMany->correlationTableName`", $statement->string);
        $this->assertStringContainsString("PRIMARY KEY", $statement->string);
        $this->assertStringContainsString("`$manyToMany->orderColumnName`", $statement->string);
        $this->assertStringContainsString("`$manyToMany->inverseOrderColumnName`", $statement->string);
    }

    /** @throws Exception */
    public function testDroppingTheCorrelationTableToleratesItsAbsence(): void
    {
        $manyToMany = $this->manyToMany();

        $this->assertStringContainsString(
            "DROP TABLE IF EXISTS `$manyToMany->correlationTableName`",
            $this->adapter->newDropTableStatementForManyToMany($manyToMany)->string,
        );
    }

    /**
     * The correlation table's indexes are its two foreign keys, one per side, so creating and
     * dropping them names both. Dropping a many-to-many is dropping those constraints rather
     * than plain indexes: the table exists only to relate the two entities.
     *
     * @throws Exception
     */
    public function testTheCorrelationTableIndexesAreItsForeignKeys(): void
    {
        $manyToMany = $this->manyToMany();

        $created = $this->adapter->newCreateIndexesStatementForManyToMany($manyToMany)->string;
        $dropped = $this->adapter->newDropIndexesStatementForManyToMany($manyToMany)->string;

        $this->assertStringContainsString($manyToMany->correlationTableName, $created);
        $this->assertStringContainsString("FOREIGN KEY", $created, "each side is constrained to the entity it names");
        $this->assertStringContainsString("DROP FOREIGN KEY IF EXISTS", $dropped, "and dropping tolerates a constraint that is already gone");
        $this->assertSame(2, string_search($dropped, "DROP FOREIGN KEY", SearchMethod::contains), "one per side of the relationship");
    }

    // --- Indexes ---

    /**
     * An attribute the model declares no index for has no index statement — the adapter answers
     * null rather than inventing one.
     *
     * @throws Exception
     */
    public function testAnUnindexedColumnHasNoIndexStatement(): void
    {
        $this->assertNull($this->adapter->newCreateIndexStatement($this->column("sku")));
        $this->assertNull($this->adapter->newDropIndexStatement($this->column("sku")));
    }

    /**
     * Resetting auto-increment names every entity it is given, which is what a migrator does
     * after moving rows so the next insert does not collide with an existing key.
     *
     * @throws Exception
     */
    public function testResettingAutoIncrementNamesTheEntities(): void
    {
        $statement = $this->adapter->newResetAutoIncrementStatement(new ArrayClass([$this->entity()]));

        $this->assertStringContainsString("AdapterPart", $statement->string);
    }
}
