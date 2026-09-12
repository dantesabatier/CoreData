<?php

/** @noinspection SqlDialectInspection, SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\BatchDeleteRequest;
use Sabatier\CoreData\BatchInsertRequest;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SQLBatchDeleteRequestContext;
use Sabatier\CoreData\SQLBatchInsertRequestContext;
use Sabatier\CoreData\SQLCore;
use Sabatier\CoreData\SQLFetchRequestContext;
use Sabatier\CoreData\SQLGenerator;
use Sabatier\CoreData\SQLStatement;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\ComparisonPredicateModifier;
use Sabatier\Foundation\Predicates\ComparisonPredicateOptions;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\ProcessInfo;
use Sabatier\Foundation\SortDescriptor;
use Sabatier\Foundation\URL;

/** @property string $sku */
final class GenPart extends ManagedObject
{
}

/** @property string $name */
final class GenSupplier extends ManagedObject
{
}

/**
 * Characterization tests for SQLGenerator, which turns a FetchRequest into MariaDB SQL.
 *
 * This is the largest untested surface in the framework and the one a consumer can least
 * inspect: "no public SQL" means every query a user gets is whatever this class emits, and a
 * wrong clause shows up as missing or duplicated rows rather than as an error. Its whole
 * surface bar the constructor and buildDerivationExpression() is private, so the contract
 * under test is the generated statement — `->statement->string` and `->arguments` — never an
 * individual method. That is deliberate: [[feedback-use-existing-generator-pipeline]] and
 * [[feedback-hydrator-do-not-touch]] both say the pipeline and its consumer contract are fixed,
 * so these tests pin what it produces today and must not be "corrected" to a nicer SQL shape.
 *
 * The generator needs a real SQLCore because it reads the SQLModel (table names, column names,
 * join topology) off the store, but generating never touches the connection — only
 * SQLFetchRequestContext::$queryStatement executes, and nothing here reads it. So the whole
 * suite builds one stack per test and asserts strings: no rows, no fixtures, no query cost.
 *
 * Two hazards this file has to respect, both already paid for elsewhere in the suite:
 * contexts must be released or each test leaks the connection its store holds open
 * (see project-suite-connection-limit), and ProcessInfo caches the .env once per process, so
 * the schema name is read rather than set.
 */
final class SQLGeneratorTest extends TestCase
{
    /** @var list<ManagedObjectContext> Every context built by a test, detached in tearDown. */
    private array $contexts = [];

    /** The store under test; one per test case, opened in setUp. */
    private SQLCore $store;

    /** The context bound to $store. */
    private ManagedObjectContext $context;

    /**
     * A Part/Supplier model: one to-one from Part, its to-many inverse from Supplier. Enough
     * topology for join generation without a second entity's worth of noise.
     */
    private static function model(): ManagedObjectModel
    {
        $sku = new AttributeDescription();
        $sku->name = "sku";
        $sku->type = AttributeType::string;

        $qty = new AttributeDescription();
        $qty->name = "qty";
        $qty->type = AttributeType::integer32;
        $qty->isOptional = true;

        $supplier = new RelationshipDescription();
        $supplier->name = "supplier";
        $supplier->lazyDestinationEntityName = "GenSupplier";
        $supplier->lazyInverseRelationshipName = "parts";

        $part = new EntityDescription();
        $part->name = "GenPart";
        $part->managedObjectClassName = GenPart::class;
        $part->properties = new ArrayClass([$sku, $qty, $supplier]);

        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $parts = new RelationshipDescription();
        $parts->name = "parts";
        $parts->lazyDestinationEntityName = "GenPart";
        $parts->lazyInverseRelationshipName = "supplier";
        $parts->isToMany = true;

        $supplierEntity = new EntityDescription();
        $supplierEntity->name = "GenSupplier";
        $supplierEntity->managedObjectClassName = GenSupplier::class;
        $supplierEntity->properties = new ArrayClass([$name, $parts]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$part, $supplierEntity]);
        return $model;
    }

    /**
     * A live SQL store over the schema named in the environment.
     *
     * The database is created here rather than assumed: ProcessInfo caches the .env once per
     * process, so this suite cannot choose its own schema name — it gets whichever one the
     * process already read. Running the whole suite that name belongs to a migration case that
     * creates and drops it, so relying on it made this file pass only when something else had
     * run first. Creating it makes the file self-sufficient under --filter.
     *
     * @param ManagedObjectContext|null $context Set to the context bound to the returned store.
     * @throws Exception
     */
    private function store(?ManagedObjectContext &$context = null): SQLCore
    {
        $schema = ProcessInfo::processInfo()->environment["SQL_SCHEMA_NAME"] ?? "coredata_migration_test";
        new PDO("mysql:host=127.0.0.1", "root", null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
            ->exec("CREATE DATABASE IF NOT EXISTS `$schema`");
        $coordinator = new PersistentStoreCoordinator(self::model());
        $store = $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, new URL("sql://$schema"));
        self::assertInstanceOf(SQLCore::class, $store);

        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->contexts[] = $context;
        return $store;
    }

    /**
     * The statement a request generates. This is the single entry point the tests use, because
     * it is the single entry point the framework uses. It reuses the stack request() opened
     * rather than building a second one — every stack holds its own SQL connection open.
     *
     * @throws Exception
     */
    private function statementFor(FetchRequest $request): SQLStatement
    {
        $statement = new SQLGenerator(new SQLFetchRequestContext($request, $this->context, $this->store))->statement;
        // A fetch always generates; a null here would mean the generator declined the request,
        // which for these inputs is itself the failure worth reporting.
        $this->assertInstanceOf(SQLStatement::class, $statement, "a fetch request must generate a statement");
        return $statement;
    }

    /**
     * A fetch request against one of the model's entities, resolved off the live store so the
     * entity carries the same identity the generator will see.
     *
     * @throws Exception
     */
    private function request(string $entityName = "GenPart"): FetchRequest
    {
        $entity = $this->store->model->entitiesByName[$entityName];
        $this->assertNotNull($entity, "$entityName must exist in the SQL model");

        $request = new FetchRequest();
        $request->entity = $entity->entityDescription;
        return $request;
    }

    /**
     * One stack per test. Building it here rather than per helper call keeps each case to a
     * single SQL connection.
     *
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        $this->store = $this->store($context);
        $this->context = $context ?? throw new Exception("store() must bind a context");
    }

    #[Override]
    protected function tearDown(): void
    {
        // Assigning a coordinator registers the context with the notification centre, which holds
        // it — and the connection its store opened — for the whole process. Clearing it is what
        // frees them; without this a suite this size exhausts max_connections.
        foreach ($this->contexts as $context) {
            $context->persistentStoreCoordinator = null;
        }
        $this->contexts = [];
        gc_collect_cycles();
    }

    // --- The shape of a plain SELECT ---

    /**
     * The baseline: an unqualified fetch selects the bookkeeping columns the hydrator needs
     * plus every modeled attribute, and nothing else.
     *
     * @throws Exception
     */
    public function testPlainFetchSelectsBookkeepingColumnsAndAttributes(): void
    {
        $statement = $this->statementFor($this->request());

        $this->assertSame(
            "SELECT GenPart.objectID, GenPart.entityName, GenPart.version, GenPart.sku, GenPart.qty FROM `GenPart`",
            $statement->string,
        );
        $this->assertTrue($statement->arguments->isEmpty, "a fetch without a predicate binds nothing");
    }

    /**
     * objectID, entityName and version are what SQLFetchRequestContext keys snapshots by, so
     * they must be present however the select list is narrowed. A projection drops attributes,
     * never the bookkeeping.
     *
     * @throws Exception
     */
    public function testProjectionKeepsBookkeepingColumns(): void
    {
        $request = $this->request();
        $request->propertiesToFetch = new ArrayClass(["sku"]);

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("GenPart.objectID", $string);
        $this->assertStringContainsString("GenPart.entityName", $string);
        $this->assertStringContainsString("GenPart.sku", $string);
        $this->assertStringNotContainsString("GenPart.qty", $string, "an attribute outside the projection is not selected");
    }

    /**
     * A count does not project columns at all — it asks the database for the number, which is
     * the whole point of countResultType.
     *
     * @throws Exception
     */
    public function testCountResultTypeSelectsACount(): void
    {
        $request = $this->request();
        $request->resultType = FetchRequestResultType::countResultType;

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("COUNT(", $string);
        $this->assertStringNotContainsString("GenPart.sku", $string, "a count selects no attributes");
    }

    /**
     * The attribute payload is controlled by includesPropertyValues, NOT by the result type:
     * a managedObjectIDResultType fetch still selects every attribute. That is deliberate — the
     * flag is the same one Apple's Core Data uses for this — so a caller who wants the cheap
     * identity-only query clears the flag rather than changing the result type.
     *
     * @throws Exception
     */
    public function testResultTypeAloneDoesNotNarrowTheSelectList(): void
    {
        $request = $this->request();
        $request->resultType = FetchRequestResultType::managedObjectIDResultType;

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("GenPart.objectID", $string);
        $this->assertStringContainsString("GenPart.sku", $string, "the result type does not drop attributes");
    }

    /**
     * Clearing includesPropertyValues is what produces the identity-only query a faulting
     * relationship wants: bookkeeping columns, no attribute payload.
     *
     * @throws Exception
     */
    public function testClearingIncludesPropertyValuesDropsTheAttributes(): void
    {
        $request = $this->request();
        $request->includesPropertyValues = false;

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("GenPart.objectID", $string);
        $this->assertStringNotContainsString("GenPart.sku", $string, "no attribute is selected without includesPropertyValues");
        $this->assertStringNotContainsString("GenPart.qty", $string);
    }

    // --- Clause order ---

    /**
     * MariaDB requires WHERE before ORDER BY before LIMIT before OFFSET, and the generator
     * appends clauses in method-call order rather than sorting them — so their relative
     * position is a real invariant, not a formatting detail.
     *
     * @throws Exception
     */
    public function testClausesAppearInSQLOrder(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format("sku == %@", new ArrayClass(["A-1"]));
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("sku", true)]);
        $request->fetchLimit = 10;
        $request->fetchOffset = 5;

        $string = $this->statementFor($request)->string;

        $where = strpos($string, "WHERE");
        $order = strpos($string, "ORDER BY");
        $limit = strpos($string, "LIMIT");
        $offset = strpos($string, "OFFSET");

        $this->assertIsInt($where);
        $this->assertIsInt($order);
        $this->assertIsInt($limit);
        $this->assertIsInt($offset);
        $this->assertLessThan($order, $where, "WHERE precedes ORDER BY");
        $this->assertLessThan($limit, $order, "ORDER BY precedes LIMIT");
        $this->assertLessThan($offset, $limit, "LIMIT precedes OFFSET");
    }

    /**
     * A sort descriptor's ascending flag picks the direction keyword, and a descending sort is
     * the case a caller notices immediately when it is wrong.
     *
     * @throws Exception
     */
    public function testDescendingSortEmitsDESC(): void
    {
        $request = $this->request();
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("sku", false)]);

        $this->assertStringContainsString("DESC", $this->statementFor($request)->string);
    }

    // --- Predicate translation ---

    /**
     * Each comparison operator and the SQL it becomes, with the arguments it binds. Values are
     * always bound, never interpolated: that is what keeps a fetch injection-proof.
     *
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function predicateTranslations(): array
    {
        return [
            "equality" => ["sku == %@", ["zzq-1"], ["GenPart.sku = ?"]],
            // `<>` rather than `!=`: both are valid MariaDB, this is the one the generator picks.
            "inequality" => ["sku != %@", ["zzq-1"], ["GenPart.sku <> ?"]],
            "greater than" => ["qty > %d", ["5"], ["GenPart.qty > ?"]],
            "greater or equal" => ["qty >= %d", ["5"], ["GenPart.qty >= ?"]],
            "less than" => ["qty < %d", ["5"], ["GenPart.qty < ?"]],
            "less or equal" => ["qty <= %d", ["5"], ["GenPart.qty <= ?"]],
            "between" => ["qty BETWEEN {%d, %d}", ["1", "9"], ["BETWEEN"]],
            "like" => ["sku LIKE %@", ["zzq%"], ["LIKE"]],
            "contains" => ["sku CONTAINS %@", ["zzq"], ["LIKE"]],
            "begins with" => ["sku BEGINSWITH %@", ["zzq"], ["LIKE"]],
            "ends with" => ["sku ENDSWITH %@", ["zzq"], ["LIKE"]],
        ];
    }

    /**
     * @param string $format The predicate as a caller writes it.
     * @param list<string> $arguments Its bound values.
     * @param list<string> $expectedFragments SQL that must appear in the WHERE clause.
     * @throws Exception
     */
    #[DataProvider("predicateTranslations")]
    public function testPredicateTranslatesToSQL(string $format, array $arguments, array $expectedFragments): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format($format, new ArrayClass($arguments));

        $statement = $this->statementFor($request);

        foreach ($expectedFragments as $fragment) {
            $this->assertStringContainsString($fragment, $statement->string, "$format must generate $fragment");
        }
        $this->assertFalse($statement->arguments->isEmpty, "a comparison binds its value rather than interpolating it");
        foreach ($arguments as $argument) {
            // "zzq" cannot occur in a table or column name of this model, so its absence really
            // does mean the value was bound; a one-letter needle would match the SQL incidentally.
            if (str_contains($argument, "zzq")) {
                $this->assertStringNotContainsString("zzq", $statement->string, "a bound value never appears in the SQL text");
            }
        }
    }

    /**
     * IN requires a non-empty sequence on the right — a scalar is rejected outright — and each
     * element is bound rather than interpolated into the list.
     *
     * @throws Exception
     */
    public function testInPredicateBindsEveryElement(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format("sku IN %@", new ArrayClass([new ArrayClass(["zzq-1", "zzq-2", "zzq-3"])]));

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("IN (", $statement->string);
        $this->assertSame(3, $statement->arguments->count, "one binding per element");
        $this->assertStringNotContainsString("zzq", $statement->string, "the elements are bound, not interpolated");
    }

    /**
     * A nil comparison keeps the value bound and emits `IS ?` rather than the literal
     * `IS NULL`. Measured against MariaDB 12.3: `WHERE qty IS ?` with a null binding returns
     * exactly the rows `WHERE qty IS NULL` does, so this is a valid spelling and not a defect —
     * do not "fix" it into a literal.
     *
     * @throws Exception
     */
    public function testNilComparisonEmitsIsWithABoundNull(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format("qty == nil");

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("GenPart.qty IS ?", $statement->string);
        $this->assertSame(1, $statement->arguments->count, "the null is bound");
    }

    /**
     * AND and OR both nest their subclauses in parentheses. Without them, mixing the two
     * reassociates the predicate and silently changes which rows match.
     *
     * @throws Exception
     */
    public function testCompoundPredicateParenthesizesItsSubclauses(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format("(sku == %@ OR sku == %@) AND qty > %d", new ArrayClass(["A", "B", "1"]));

        $statement = $this->statementFor($request);

        $this->assertStringContainsString(" OR ", $statement->string);
        $this->assertStringContainsString(" AND ", $statement->string);
        $this->assertStringContainsString("((", $statement->string, "a nested compound keeps its own parentheses");
        $this->assertSame(3, $statement->arguments->count, "each leaf binds one value");
    }

    /**
     * BEGINSWITH emits both LIKE and LIKE BINARY, binding the pattern twice. The pair is what
     * makes the match case-sensitive on a case-insensitive collation; see
     * [[project-like-binary-default]], which pins the same behavior end to end.
     *
     * @throws Exception
     */
    public function testPrefixMatchIsCaseSensitiveViaLikeBinary(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format("sku BEGINSWITH %@", new ArrayClass(["AB"]));

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("LIKE BINARY", $statement->string);
        $this->assertSame(2, $statement->arguments->count, "the pattern is bound once per LIKE");
        $this->assertSame(["AB%", "AB%"], $statement->arguments->map(fn(mixed $value): string => (string)$value)->array);
    }

    /**
     * A truth-valued predicate short-circuits into a constant rather than a column comparison,
     * which is what lets a caller pass Predicate::value() as a no-op filter.
     *
     * @throws Exception
     */
    public function testConstantPredicateBecomesAConstant(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::value(true);

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("WHERE", $statement->string);
        $this->assertTrue($statement->arguments->isEmpty, "a constant predicate binds nothing");
    }

    // --- Joins ---

    /**
     * A predicate over a to-one key path joins the destination table instead of running a
     * second query, and binds the compared value.
     *
     * @throws Exception
     */
    public function testPredicateAcrossToOneRelationshipJoins(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format("supplier.name == %@", new ArrayClass(["Acme"]));

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("JOIN", $statement->string);
        $this->assertStringContainsString("GenSupplier", $statement->string);
        $this->assertSame(1, $statement->arguments->count);
    }

    /**
     * The inverse direction: a predicate from the to-many side. This is the shape that
     * multiplies rows, which is why the generator has to decide between a join and a subquery
     * rather than always joining.
     *
     * @throws Exception
     */
    public function testPredicateAcrossToManyRelationshipReachesTheDestination(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = Predicate::format("parts.sku == %@", new ArrayClass(["A-1"]));

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("GenPart", $statement->string, "the to-many destination is reached");
        $this->assertSame(1, $statement->arguments->count);
    }

    /**
     * A limit combined with a row-multiplying join cannot be applied directly — LIMIT would cut
     * joined duplicate rows rather than distinct entities. MariaDB also rejects LIMIT inside an
     * IN subquery (error 1235), so whatever the generator does here it must not be a bare
     * `IN (... LIMIT ...)`; see [[project-mariadb-limit-in-subquery]].
     *
     * @throws Exception
     */
    public function testLimitWithToManyJoinDoesNotPutLimitInsideAnInSubquery(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = Predicate::format("parts.sku == %@", new ArrayClass(["A-1"]));
        $request->fetchLimit = 5;

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("LIMIT", $string);
        if (str_contains($string, "IN (")) {
            // The workaround is a derived table: `IN (SELECT ... FROM (SELECT ... LIMIT n) ...)`.
            // The LIMIT is legal there, so what must hold is that a nested SELECT stands between
            // the IN and the LIMIT — never `IN (SELECT ... LIMIT n)` on its own.
            $this->assertMatchesRegularExpression(
                "/IN \(SELECT.*FROM \(SELECT.*LIMIT/s",
                $string,
                "a LIMIT under IN must sit inside a derived table, which MariaDB accepts",
            );
        }
    }

    // --- Identifier quoting ---

    /**
     * The table name is backquoted, so an entity whose name collides with a reserved word still
     * produces valid SQL.
     *
     * @throws Exception
     */
    public function testTableNameIsQuoted(): void
    {
        $this->assertStringContainsString("`GenPart`", $this->statementFor($this->request())->string);
    }

    /**
     * Generating the same request twice yields the same statement. The generator memoizes and
     * mutates a shared buffer through resetSQL()/appendSQL(), so a leaked buffer between runs
     * would show up here first.
     *
     * @throws Exception
     */
    public function testGenerationIsRepeatable(): void
    {
        $request = $this->request();
        $request->predicate = Predicate::format("sku == %@", new ArrayClass(["A-1"]));

        $first = $this->statementFor($request);
        $second = $this->statementFor($request);

        $this->assertSame($first->string, $second->string);
        $this->assertSame(
            $first->arguments->map(fn(mixed $value): string => (string)$value)->array,
            $second->arguments->map(fn(mixed $value): string => (string)$value)->array,
        );
    }

    // --- Aggregation: GROUP BY and HAVING ---

    /**
     * Grouping emits GROUP BY over the named properties. An aggregate query returns rows that
     * are not entities, so it only makes sense with dictionaryResultType — the atomic stores
     * reject the combination outright, and the SQL side has to agree on the shape.
     *
     * @throws Exception
     */
    public function testGroupByEmitsTheClause(): void
    {
        $request = $this->request();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToFetch = new ArrayClass(["sku"]);
        $request->propertiesToGroupBy = new ArrayClass(["sku"]);

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("GROUP BY", $string);
        $this->assertStringContainsString("GenPart.sku", $string);
    }

    /**
     * A having predicate filters whole groups, so it must land in HAVING and not in WHERE —
     * WHERE cannot see an aggregate, and MariaDB would reject it there.
     *
     * @throws Exception
     */
    public function testHavingPredicateLandsInHavingAfterGroupBy(): void
    {
        $request = $this->request();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToFetch = new ArrayClass(["sku"]);
        $request->propertiesToGroupBy = new ArrayClass(["sku"]);
        $request->havingPredicate = Predicate::format("qty > %d", new ArrayClass(["2"]));

        $string = $this->statementFor($request)->string;

        $having = strpos($string, "HAVING");
        $this->assertIsInt($having, "a having predicate emits a HAVING clause");
        $this->assertGreaterThan(strpos($string, "GROUP BY"), $having, "HAVING follows GROUP BY");
    }

    // --- Subqueries over a to-many relationship ---

    /**
     * ANY over a to-many becomes a correlated EXISTS rather than a join: a join would multiply
     * the outer rows once per matching child, which is not what "any child matches" means.
     *
     * @throws Exception
     */
    public function testAnyOverToManyBecomesExists(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("parts.sku"),
            Expression::expressionForConstantValue("zzq-1"),
            PredicateOperatorType::equalTo,
            ComparisonPredicateModifier::any,
        );

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("EXISTS", $statement->string);
        $this->assertStringNotContainsString("NOT EXISTS", $statement->string, "ANY is a bare EXISTS");
        $this->assertStringNotContainsString("zzq-1", $statement->string, "the inner constant is bound, not inlined");
        $this->assertSame(["zzq-1"], $statement->arguments->array, "the subquery's argument reaches the outer statement");
    }

    /**
     * A constant carrying a quote travels as a bound argument, so it never becomes part of the
     * SQL text and no escaping is involved.
     *
     * This case used to pin the opposite. The subquery paths were the one place the generator
     * inlined a constant, escaping it on the way in — safe, but it made production SQL depend on
     * the formatter, a debug and pretty-printing layer, and denied MariaDB a reusable prepared
     * statement. SQLSelectPredicateKeyPathTest carries the end-to-end half of the same change:
     * a real fetch for this value still resolves to exactly its own row.
     *
     * @throws Exception
     */
    public function testSubqueryBindsAConstantContainingAQuote(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("parts.sku"),
            Expression::expressionForConstantValue("O'Brien"),
            PredicateOperatorType::equalTo,
            ComparisonPredicateModifier::any,
        );

        $statement = $this->statementFor($request);

        $this->assertStringNotContainsString("O'Brien", $statement->string, "the value is bound, so it is absent from the SQL");
        $this->assertSame(["O'Brien"], $statement->arguments->array, "the quote travels untouched as an argument");
    }

    /**
     * The `direct` modifier of the same predicate joins instead of building a subquery. Both
     * paths bind their value now, so what this pins is the structural difference — JOIN versus
     * EXISTS — which is what decides whether the query can multiply the outer rows.
     *
     * This contrast is how the old inlining was found: the same predicate bound its value here
     * and interpolated it on the ANY path, which is what made the asymmetry visible.
     *
     * @throws Exception
     */
    public function testDirectModifierBindsAndJoinsInsteadOfSubquerying(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("parts.sku"),
            Expression::expressionForConstantValue("zzq-1"),
            PredicateOperatorType::equalTo,
            ComparisonPredicateModifier::direct,
        );

        $statement = $this->statementFor($request);

        $this->assertStringContainsString("JOIN", $statement->string);
        $this->assertStringNotContainsString("EXISTS", $statement->string);
        $this->assertSame(1, $statement->arguments->count, "the direct path binds its value");
        $this->assertStringNotContainsString("zzq", $statement->string);
    }

    /**
     * ALL is the pair "there is at least one child, and none of them fails" — an EXISTS with a
     * NOT EXISTS over the negated inner predicate. The first half matters: without it an entity
     * with no children would vacuously satisfy ALL.
     *
     * @throws Exception
     */
    public function testAllOverToManyBecomesExistsAndNotExists(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("parts.sku"),
            Expression::expressionForConstantValue("zzq-1"),
            PredicateOperatorType::equalTo,
            ComparisonPredicateModifier::all,
        );

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("EXISTS", $string);
        $this->assertStringContainsString("NOT EXISTS", $string, "ALL excludes the rows with a non-matching child");
        $this->assertStringContainsString(" AND ", $string, "both halves are required");
    }

    /**
     * Each subquery gets its own generated table alias, or the correlation would bind to the
     * wrong scope and the EXISTS would compare a table against itself.
     *
     * @throws Exception
     */
    public function testSubqueryUsesAGeneratedTableAlias(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("parts.sku"),
            Expression::expressionForConstantValue("zzq-1"),
            PredicateOperatorType::equalTo,
            ComparisonPredicateModifier::any,
        );

        $string = $this->statementFor($request)->string;

        $this->assertMatchesRegularExpression('/AS t\d+/', $string, "the subquery names its own alias");
    }

    // --- Batch requests, which generate through their own contexts ---

    /**
     * A batch insert generates an INSERT with the values bound, bypassing the object graph. It
     * goes through SQLBatchInsertRequestContext rather than the fetch context, so this is a
     * second entry point into the same generator.
     *
     * @throws Exception
     */
    public function testBatchInsertGeneratesAnInsert(): void
    {
        $entity = $this->store->model->entitiesByName["GenPart"];
        $this->assertNotNull($entity);

        // The handler is pulled until it returns false, so the rows it hands out live in a
        // queue it consumes rather than in an index the test has to keep in step with it.
        $rows = new ArrayClass([["sku" => "zzq-1", "qty" => 1], ["sku" => "zzq-2", "qty" => 2]]);
        $request = new BatchInsertRequest(
            $entity->entityDescription,
            dictionaryHandler: static function (Dictionary $snapshot) use ($rows): bool {
                if ($rows->isEmpty) {
                    return false;
                }
                /** @var array<string, mixed> $row */
                $row = $rows->popFirst();
                foreach ($row as $key => $value) {
                    $snapshot[$key] = $value;
                }
                return true;
            },
        );

        $context = new SQLBatchInsertRequestContext($request, $this->context, $this->store);
        $statement = new SQLGenerator($context)->statement;

        $this->assertInstanceOf(SQLStatement::class, $statement);
        $this->assertStringContainsString("INSERT", $statement->string);
        $this->assertStringContainsString("`GenPart`", $statement->string);
        $this->assertStringNotContainsString("zzq", $statement->string, "the values are bound, not interpolated");
        $this->assertTrue($rows->isEmpty, "the generator pulls the handler until it is exhausted");
    }

    /**
     * A batch delete is driven by the fetch request it wraps, so its predicate has to reach the
     * generated statement — otherwise the request would match the whole table.
     *
     * @throws Exception
     */
    public function testBatchDeleteCarriesItsFetchPredicate(): void
    {
        $fetchRequest = $this->request();
        $fetchRequest->predicate = Predicate::format("sku == %@", new ArrayClass(["zzq-1"]));

        $context = new SQLBatchDeleteRequestContext(new BatchDeleteRequest($fetchRequest), $this->context, $this->store);
        $statement = new SQLGenerator($context)->statement;

        $this->assertInstanceOf(SQLStatement::class, $statement);
        $this->assertStringContainsString("WHERE", $statement->string, "a batch delete keeps its predicate");
        $this->assertSame(1, $statement->arguments->count);
    }

    // --- MATCHES: regular expressions ---

    /**
     * MATCHES becomes REGEXP, and case sensitivity is the default: without the caseInsensitive
     * option the operator is REGEXP BINARY. Same rationale as the LIKE BINARY pair — the
     * collation is case-insensitive, so the binary form is what makes a match respect case.
     *
     * @throws Exception
     */
    public function testMatchesBecomesCaseSensitiveRegexp(): void
    {
        $request = $this->request();
        $request->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("sku"),
            Expression::expressionForConstantValue("^zzq"),
            PredicateOperatorType::matches,
        );

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("REGEXP BINARY", $string);
    }

    /**
     * With the caseInsensitive option the BINARY qualifier is dropped, leaving a plain REGEXP.
     *
     * @throws Exception
     */
    public function testCaseInsensitiveMatchesDropsBinary(): void
    {
        $request = $this->request();
        $request->predicate = new ComparisonPredicate(
            Expression::expressionForKeyPath("sku"),
            Expression::expressionForConstantValue("^zzq"),
            PredicateOperatorType::matches,
            ComparisonPredicateModifier::direct,
            ComparisonPredicateOptions::caseInsensitive,
        );

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("REGEXP", $string);
        $this->assertStringNotContainsString("REGEXP BINARY", $string, "the option removes the binary qualifier");
    }

    // --- Collection operators over a to-many ---

    /**
     * `@count` over a to-many becomes a correlated COUNT subquery: the number of children is
     * not a column, so it cannot be compared without asking the database to count them.
     *
     * @throws Exception
     */
    public function testCountCollectionOperatorBuildsASubquery(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = Predicate::format("parts.@count > %d", new ArrayClass(["2"]));

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("COUNT(", $string);
        $this->assertStringContainsString("SELECT", substr($string, (int)strpos($string, "WHERE")), "the count is computed by a subquery");
    }

    /**
     * An aggregate over a child property has to name that property inside the subquery, or it
     * would sum the wrong column.
     *
     * Note the key path order: the property comes BEFORE the operator — `parts.qty.@sum`, not
     * Apple's `parts.@sum.qty`. Foundation's kvc_components() splits at the "@" and treats
     * everything after it as the operator, so the Apple spelling parses as the operator
     * "sum.qty" with no property and is rejected outright.
     *
     * @throws Exception
     */
    public function testSumCollectionOperatorAggregatesTheNamedProperty(): void
    {
        $request = $this->request("GenSupplier");
        $request->predicate = Predicate::format("parts.qty.@sum > %d", new ArrayClass(["10"]));

        $string = $this->statementFor($request)->string;

        $this->assertStringContainsString("SUM(", $string);
        $this->assertStringContainsString("qty", $string);
    }

    // --- Derived attribute expressions, via the one public builder ---

    /**
     * buildDerivationExpression is the generator's only public method besides the constructor:
     * SQLAdapter calls it to turn a derivation into a GENERATED ALWAYS AS column. A plain key
     * path becomes a column reference.
     *
     * @throws Exception
     */
    public function testDerivationExpressionBuildsAColumnReference(): void
    {
        $generator = new SQLGenerator(new SQLFetchRequestContext($this->request(), $this->context, $this->store));

        $string = $generator->buildDerivationExpression(Expression::expressionForKeyPath("sku"));

        $this->assertStringContainsString("sku", $string);
    }

    /**
     * A function expression becomes the SQL function of the same name. This is the path that
     * makes a derived attribute like `UPPER(sku)` possible without writing SQL.
     *
     * @throws Exception
     */
    public function testDerivationExpressionBuildsAFunctionCall(): void
    {
        $generator = new SQLGenerator(new SQLFetchRequestContext($this->request(), $this->context, $this->store));

        $string = $generator->buildDerivationExpression(Expression::expressionForFunction(
            "uppercase:",
            new ArrayClass([Expression::expressionForKeyPath("sku")]),
        ));

        $this->assertStringContainsString("UPPER", $string);
        $this->assertStringContainsString("sku", $string);
    }

    /**
     * A conditional expression becomes MariaDB's IF(). The generator also reports it as
     * non-deterministic, which is what decides whether SQLAdapter emits the generated column as
     * PERSISTENT or VIRTUAL — a wrong answer there produces a column the server refuses to store.
     *
     * @throws Exception
     */
    public function testConditionalExpressionBecomesIfAndIsNonDeterministic(): void
    {
        $generator = new SQLGenerator(new SQLFetchRequestContext($this->request(), $this->context, $this->store));
        $isDeterministic = true;
        $condition = Predicate::format("qty > %d", new ArrayClass(["0"]));
        $this->assertNotNull($condition, "the fixture predicate must parse");

        $string = $generator->buildDerivationExpression(
            Expression::expressionForConditional(
                $condition,
                Expression::expressionForConstantValue("hi"),
                Expression::expressionForConstantValue("lo"),
            ),
            isDeterministic: $isDeterministic,
        );

        // The branch ORDER is the point: IF(cond, true, false). Asserting only that "IF(" appears
        // lets a swap of the two branches through, which inverts the column's value silently.
        // The branches are "hi"/"lo" rather than "yes"/"no" because Foundation reads the latter
        // as booleans (the plist convention), so they would render as the literals true/false.
        $this->assertMatchesRegularExpression("/IF\(.+, 'hi', 'lo'\)/", $string, "the true branch comes first");
        $this->assertFalse($isDeterministic, "a conditional cannot be persisted as a deterministic column");
    }
}
