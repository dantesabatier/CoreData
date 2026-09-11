<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchedPropertyDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;

/**
 * @property string $name
 * @property Set<AccessPlayer>|null $players
 * @property ArrayClass<AccessPlayer>|null $roster
 */
final class AccessTeam extends ManagedObject
{
}

/**
 * @property string $name
 * @property AccessTeam|null $team
 */
final class AccessPlayer extends ManagedObject
{
}

/**
 * Tests relationship ID and fetched-property array access on managed objects.
 *
 * The regressions distinguish to-many, to-one and invalid relationship keys, and verify fetched
 * requests return iterable matching and empty results.
 */
final class ManagedObjectRelationshipAccessTest extends TestCase
{
    private string $storePath;
    private ManagedObjectContext $context;

    private static function model(): ManagedObjectModel
    {
        $teamName = new AttributeDescription();
        $teamName->name = "name";
        $teamName->type = AttributeType::string;

        $players = new RelationshipDescription();
        $players->name = "players";
        $players->lazyDestinationEntityName = "AccessPlayer";
        $players->lazyInverseRelationshipName = "team";
        $players->isToMany = true;
        $players->isOptional = true;

        $rosterFetch = new FetchRequest("AccessPlayer");
        $rosterFetch->predicate = Predicate::format("team == \$FETCH_SOURCE");

        $roster = new FetchedPropertyDescription();
        $roster->name = "roster";
        $roster->fetchRequest = $rosterFetch;

        $team = new EntityDescription();
        $team->name = "AccessTeam";
        $team->managedObjectClassName = AccessTeam::class;
        $team->properties = new ArrayClass([$teamName, $players, $roster]);

        $playerName = new AttributeDescription();
        $playerName->name = "name";
        $playerName->type = AttributeType::string;

        $teamRelationship = new RelationshipDescription();
        $teamRelationship->name = "team";
        $teamRelationship->lazyDestinationEntityName = "AccessTeam";
        $teamRelationship->lazyInverseRelationshipName = "players";
        $teamRelationship->isOptional = true;

        $player = new EntityDescription();
        $player->name = "AccessPlayer";
        $player->managedObjectClassName = AccessPlayer::class;
        $player->properties = new ArrayClass([$playerName, $teamRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$team, $player]);
        return $model;
    }

    /** @throws Exception */
    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-access-test-" . uniqid("", true) . ".xml";
        $storeURL = new URL("file://" . str_replace("\\", "/", $this->storePath));

        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $storeURL);
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->context->persistentStoreCoordinator = null;
        if (is_file($this->storePath)) {
            unlink($this->storePath);
        }
    }

    /**
     * @param list<string> $playerNames
     * @throws Exception
     */
    private function savedTeam(string $name, array $playerNames): AccessTeam
    {
        $team = new AccessTeam($this->context);
        $team->name = $name;
        foreach ($playerNames as $playerName) {
            $player = new AccessPlayer($this->context);
            $player->name = $playerName;
            $player->setValueForKey($team, "team");
        }
        $this->context->save();
        return $team;
    }

    /** @throws Exception */
    public function testToManyRelationshipAnswersAnIDPerMember(): void
    {
        $team = $this->savedTeam("first", ["a", "b", "c"]);

        $objectIDs = $team->objectIDsForRelationshipNamed("players");

        $this->assertSame(3, $objectIDs->count, "one identifier per player");
        /** @var ArrayClass<string> $names */
        $names = $objectIDs->map(function (ManagedObjectID $objectID): string {
            $this->assertInstanceOf(ManagedObjectID::class, $objectID);
            return (string)$this->context->object($objectID)->valueForKey("name");
        });
        $this->assertSame(["a", "b", "c"], $names->sort()->array, "and each one names a player of this team");
    }

    /**
     * The primitive accessors answer the unresolved fault, so asking a relationship that nothing has
     * read yet used to report no identifiers at all.
     * @throws Exception
     */
    public function testAnUnreadToManyRelationshipStillAnswersItsIDs(): void
    {
        $this->savedTeam("third", ["x", "y"]);
        $this->context->reset();

        $team = $this->context->fetch(AccessTeam::fetchRequest())->first;
        $this->assertNotNull($team);

        $this->assertSame(2, $team->objectIDsForRelationshipNamed("players")->count, "one identifier per player");
    }

    /** @throws Exception */
    public function testToOneRelationshipAnswersASingleID(): void
    {
        $team = $this->savedTeam("second", ["only"]);
        $player = $this->context->fetch(AccessPlayer::fetchRequest())->first;
        $this->assertNotNull($player);

        $objectIDs = $player->objectIDsForRelationshipNamed("team");

        $this->assertSame(1, $objectIDs->count);
        $this->assertTrue($objectIDs->first?->isEqual($team->objectID), "it is the team the player belongs to");
    }

    /** @throws Exception */
    public function testAnEmptyToManyAnswersNoIDs(): void
    {
        $team = $this->savedTeam("empty", []);

        $this->assertTrue($team->objectIDsForRelationshipNamed("players")->isEmpty);
    }

    /** @throws Exception */
    public function testAnUnknownRelationshipRaises(): void
    {
        $team = $this->savedTeam("third", ["a"]);

        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/does not contains a relationship named \"absent\"/");
        $team->objectIDsForRelationshipNamed("absent");
    }

    /** @throws Exception */
    public function testNamingAnAttributeRaises(): void
    {
        $team = $this->savedTeam("fourth", ["a"]);

        $this->expectException(InternalInconsistencyException::class);
        $team->objectIDsForRelationshipNamed("name");
    }

    /** @throws Exception */
    public function testAFetchedPropertyRunsItsRequest(): void
    {
        $team = $this->savedTeam("fifth", ["a", "b"]);
        $team->mutableArrayValueForKey("roster");
        $roster = $team->valueForKey("roster");

        $this->assertSame(2, $roster->count, "the fetched property found both players");
    }

    /** @throws Exception */
    public function testAFetchedPropertyWithNoMatchesIsEmpty(): void
    {
        $team = $this->savedTeam("sixth", []);
        $team->mutableArrayValueForKey("roster");

        $this->assertTrue($team->valueForKey("roster")->isEmpty);
    }
}
