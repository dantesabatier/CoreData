<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Set;

/**
 * @property string $name
 * @property Set<Symphony> $works
 * @method void addWorksObject(Symphony $object)
 * @method void removeWorksObject(Symphony $object)
 * @method void addWorks(Set<Symphony> $objects)
 * @method void removeWorks(Set<Symphony> $objects)
 * @method Set<Symphony> intersectWorks(Set<Symphony> $objects)
 * @method void setWorks(Set<Symphony> $objects)
 */
final class Composer extends ManagedObject
{
}

/**
 * @property Composer $composer
 * @property string $title
 */
final class Symphony extends ManagedObject
{
}

/**
 * Regression test for ANY/ALL modifiers over a to-many relationship key path in SQL generation.
 *
 * A parsed key-path expression such as `works.title` stores only the trailing key ("title") in
 * its ->keyPath property; the leading `works` component lives in ->operand, and only ->description
 * reconstructs the full dotted path. SQLGenerator::resolveRelationshipFromKeyPath walked
 * ->keyPath, so for `ANY works.title == "x"` it saw only "title" — an attribute, not a
 * relationship — found no to-many to build the EXISTS subquery from, and raised
 * "Unable to resolve relationship from key path \"title\"" (SQLGenerator, buildClauseWithSelectPredicate).
 *
 * This surfaced in production only after Foundation began parsing multi-segment key paths into
 * nested KeyPathExpression objects (previously such predicates failed to parse at all), so every
 * ANY/ALL-over-a-to-many predicate that reached the SQL store crashed. The fix walks ->description
 * (the full path) instead of the bare ->keyPath.
 */
final class SQLSelectPredicateKeyPathTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $composerName = new AttributeDescription();
        $composerName->name = "name";
        $composerName->type = AttributeType::string;

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $works = new RelationshipDescription();
        $works->name = "works";
        $works->lazyDestinationEntityName = "Symphony";
        $works->lazyInverseRelationshipName = "composer";
        $works->isToMany = true;

        $composer = new RelationshipDescription();
        $composer->name = "composer";
        $composer->lazyDestinationEntityName = "Composer";
        $composer->lazyInverseRelationshipName = "works";
        $composer->maxCount = 1;

        $composerEntity = new EntityDescription();
        $composerEntity->name = "Composer";
        $composerEntity->managedObjectClassName = Composer::class;
        $composerEntity->properties = new ArrayClass([$composerName, $works]);

        $symphonyEntity = new EntityDescription();
        $symphonyEntity->name = "Symphony";
        $symphonyEntity->managedObjectClassName = Symphony::class;
        $symphonyEntity->properties = new ArrayClass([$title, $composer]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$composerEntity, $symphonyEntity]);
        return $model;
    }

    /** @throws Exception */
    private function seed(): void
    {
        $context = $this->bootstrap(self::model());

        $beethoven = new Composer($context);
        $beethoven->name = "Beethoven";
        $eroica = new Symphony($context);
        $eroica->title = "Eroica";
        $eroica->setValueForKey($beethoven, "composer");

        $mahler = new Composer($context);
        $mahler->name = "Mahler";
        $resurrection = new Symphony($context);
        $resurrection->title = "Resurrection";
        $resurrection->setValueForKey($mahler, "composer");

        $strauss = new Composer($context);
        $strauss->name = "Strauss";
        $tilleulenspiegel = new Symphony($context);
        $tilleulenspiegel->title = "Till O'Brien' OR '1'='1";
        $tilleulenspiegel->setValueForKey($strauss, "composer");

        $context->save();
    }

    /**
     * The crashing production shape: an ANY modifier over a to-many relationship's attribute.
     * Before the fix this raised InternalInconsistencyException during SQL generation; after it,
     * the fetch resolves to the one composer whose works include the matching title.
     *
     * @throws Exception
     */
    public function testAnyOverToManyRelationshipAttributeResolves(): void
    {
        $this->seed();

        $readContext = $this->freshContext(self::model());
        $request = Composer::fetchRequest();
        $request->predicate = Predicate::format("ANY works.title == \"Eroica\"");

        $results = $readContext->fetch($request);

        $this->assertSame(1, $results->count, "exactly one composer has a work titled 'Eroica'");
        $composer = $results->first;
        $this->assertNotNull($composer);
        $this->assertSame("Beethoven", $composer->name);
    }

    /**
     * A title matched by no work yields an empty result rather than a generation-time crash.
     *
     * @throws Exception
     */
    public function testAnyOverToManyRelationshipAttributeWithNoMatch(): void
    {
        $this->seed();

        $readContext = $this->freshContext(self::model());
        $request = Composer::fetchRequest();
        $request->predicate = Predicate::format("ANY works.title == \"Nonexistent\"");

        $this->assertSame(0, $readContext->fetch($request)->count);
    }

    /**
     * The subquery binds its constant rather than interpolating it, so a value containing quotes
     * round-trips through the store untouched.
     *
     * This is the end-to-end half of the change: asserting on generated SQL proves the "?" is
     * emitted, but only a real fetch proves MariaDB still resolves the predicate to the right row.
     *
     * Note this case passes both before and after the move to a bound parameter, and that is the
     * point: the previous inlining escaped the value correctly, so this pins equivalence rather
     * than a fixed bug. The tests that actually discriminate the two shapes are in
     * SQLGeneratorTest, which asserts on the statement instead of the result.
     *
     * @throws Exception
     */
    public function testAnyOverToManyBindsAQuoteBearingConstant(): void
    {
        $this->seed();

        $readContext = $this->freshContext(self::model());
        $request = Composer::fetchRequest();
        $request->predicate = Predicate::format("ANY works.title == %@", new ArrayClass(["Till O'Brien' OR '1'='1"]));

        $results = $readContext->fetch($request);

        $this->assertSame(1, $results->count, "the tautology stays data: it matches one title, it does not select every row");
        $composer = $results->first;
        $this->assertNotNull($composer);
        $this->assertSame("Strauss", $composer->name);
    }
}
