<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FaultingSet;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property string $name
 * @property Set<Player> $roster
 * @method void addRosterObject(Player $object)
 * @method void removeRosterObject(Player $object)
 * @method void addRoster(Set<Player> $objects)
 * @method void removeRoster(Set<Player> $objects)
 * @method Set<Player> intersectRoster(Set<Player> $objects)
 * @method void setRoster(Set<Player> $objects)
 */
final class Team extends ManagedObject
{
}

/**
 * @property int $jersey
 * @property Team $team
 */
final class Player extends ManagedObject
{
}

/**
 * Unit tests for FaultingSet — the to-many relationship collection. It stores ManagedObjectID
 * internally but presents ManagedObject to callers, coercing in both directions on every
 * mutator/lookup, and it carries a hand-written recursive merge sort that materializes elements
 * during comparison. Both are high blast radius (every to-many relationship) and had no direct
 * coverage.
 *
 * A FaultingSet needs a source object + relationship and a context whose object($id) resolves —
 * so a real (XML-backed) stack is used to mint genuine ManagedObject/ManagedObjectID pairs, and
 * the set under test is built directly on them. The set is populated with Player objects and the
 * "roster" (to-many) relationship as its backing relationship description.
 */
final class FaultingSetTest extends TestCase
{
    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $teamName = new AttributeDescription();
        $teamName->name = "name";
        $teamName->type = AttributeType::string;

        $roster = new RelationshipDescription();
        $roster->name = "roster";
        $roster->lazyDestinationEntityName = "Player";
        $roster->lazyInverseRelationshipName = "team";
        $roster->isToMany = true;

        $jersey = new AttributeDescription();
        $jersey->name = "jersey";
        $jersey->type = AttributeType::integer32;

        $team = new RelationshipDescription();
        $team->name = "team";
        $team->lazyDestinationEntityName = "Team";
        $team->lazyInverseRelationshipName = "roster";
        $team->maxCount = 1;

        $teamEntity = new EntityDescription();
        $teamEntity->name = "Team";
        $teamEntity->managedObjectClassName = Team::class;
        $teamEntity->properties = new ArrayClass([$teamName, $roster]);

        $playerEntity = new EntityDescription();
        $playerEntity->name = "Player";
        $playerEntity->managedObjectClassName = Player::class;
        $playerEntity->properties = new ArrayClass([$jersey, $team]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$teamEntity, $playerEntity]);
        return $model;
    }

    private ManagedObjectContext $context;
    private Team $team;
    private RelationshipDescription $roster;

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");

        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;

        $this->team = new Team($this->context);
        $this->team->name = "Rovers";
        /** @var RelationshipDescription $roster */
        $roster = $this->context->persistentStoreCoordinator->managedObjectModel
            ->entitiesByName["Team"]->relationshipsByName["roster"];
        $this->roster = $roster;
    }

    /**
     * @throws Exception
     */
    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    private function player(int $jersey): Player
    {
        $player = new Player($this->context);
        $player->jersey = $jersey;
        return $player;
    }

    /**
     * The jersey numbers of the set's elements, in iteration order.
     *
     * Through reduce rather than map: map projects into a Set, which deduplicates by value, so two
     * players sharing a jersey number would collapse into one and hide an element the tests assert on.
     *
     * @return list<int>
     */
    private function jerseys(FaultingSet $set): array
    {
        return $set->reduce([], function (array &$carry, Player $player): array {
            $carry[] = $player->jersey;
            return $carry;
        });
    }

    /**
     * The object-ID URIs of the set's elements, in iteration order.
     *
     * Unlike {@see jerseys()} this one can go through map(): the URIs are distinct by construction,
     * so the projected Set has nothing to deduplicate.
     *
     * @return list<string>
     */
    private function ids(FaultingSet $set): array
    {
        return $set->map(fn(Player $player): string => $player->objectID->uriRepresentation()->absoluteString)->array;
    }

    public function testSetSetClearsTheFaultFlag(): void
    {
        $set = new FaultingSet($this->team, $this->roster);

        // setSet is the normal materialization entry point: only the set-algebra mutators clear the flag, so a set filled through insert() alone stays marked as a fault and is refetched.
        $set->setSet(new Set([$this->player(1)]));
        $this->assertFalse($set->isFault, "setSet materializes the set and leaves the fault flag clear");
    }

    public function testTurnIntoFaultEmptiesAndReflags(): void
    {
        $set = new FaultingSet($this->team, $this->roster);
        $set->setSet(new Set([$this->player(1), $this->player(2)]));
        $this->assertSame(2, $set->count, "precondition: populated");

        $set->turnIntoFault();
        $this->assertTrue($set->isFault, "turnIntoFault re-flags as a fault");
        $this->assertSame(0, $set->count, "turnIntoFault drops the materialized members");
    }

    public function testInsertAcceptsAManagedObjectAndPresentsItBack(): void
    {
        $set = new FaultingSet($this->team, $this->roster);
        $set->setSet(new Set());
        $player = $this->player(9);
        $set->insert($player);

        // Stored as an ID internally, but iteration coerces back to the ManagedObject.
        $this->assertSame(1, $set->count);
        $this->assertSame([9], $this->jerseys($set), "the inserted object is presented back as a ManagedObject");
    }

    public function testContainsAndIndexOfAcceptBothManagedObjectAndObjectID(): void
    {
        $set = new FaultingSet($this->team, $this->roster);
        $player = $this->player(4);
        $set->setSet(new Set([$player]));

        $this->assertTrue($set->containsElement($player), "containsElement accepts a ManagedObject");
        $this->assertTrue($set->containsElement($player->objectID), "containsElement accepts a ManagedObjectID");
        $this->assertNotNull($set->indexOf($player->objectID), "indexOf accepts a ManagedObjectID");
        $this->assertNull($set->indexOf($this->player(99)->objectID), "a non-member ID has no index");
    }

    public function testRemoveAcceptsBothManagedObjectAndObjectID(): void
    {
        $a = $this->player(1);
        $b = $this->player(2);
        $set = new FaultingSet($this->team, $this->roster);
        $set->setSet(new Set([$a, $b]));

        $set->remove($a);                 // by ManagedObject
        $this->assertSame([2], $this->jerseys($set), "remove(ManagedObject) drops the right element");

        $set->remove($b->objectID);       // by ManagedObjectID
        $this->assertSame(0, $set->count, "remove(ManagedObjectID) drops the right element");
    }

    public function testSortOrdersByComparatorOverMaterializedObjects(): void
    {
        $set = new FaultingSet($this->team, $this->roster);
        // Insert out of order; the sort must materialize IDs to compare on jersey.
        $set->setSet(new Set([$this->player(30), $this->player(10), $this->player(20), $this->player(5)]));

        $set->sort(fn(Player $a, Player $b): int => $a->jersey <=> $b->jersey);

        $this->assertSame([5, 10, 20, 30], $this->jerseys($set), "the merge sort orders ascending by jersey");
    }

    public function testSortIsStableForEqualKeys(): void
    {
        // Two distinct players share a sort key (jersey 10); a stable sort must keep their
        // original relative order. This exercises the merge's "<= 0 takes the left run" tie-break.
        $first = $this->player(10);
        $second = $this->player(10);
        $third = $this->player(1);
        $set = new FaultingSet($this->team, $this->roster);
        $set->setSet(new Set([$first, $second, $third]));

        $this->assertSame([10, 10, 1], $this->jerseys($set), "precondition: insertion order preserved by setSet");

        $set->sort(fn(Player $a, Player $b): int => $a->jersey <=> $b->jersey);

        $this->assertSame([1, 10, 10], $this->jerseys($set), "keys are ordered ascending");
        // The two jersey-10 players must retain their relative order (first before second).
        $this->assertSame(
            [
                $third->objectID->uriRepresentation()->absoluteString,
                $first->objectID->uriRepresentation()->absoluteString,
                $second->objectID->uriRepresentation()->absoluteString,
            ],
            $this->ids($set),
            "equal-key elements keep their original relative order (stable sort)",
        );
    }

    public function testSortOfEmptyOrSingletonIsANoOp(): void
    {
        $empty = new FaultingSet($this->team, $this->roster);
        $empty->setSet(new Set());
        $empty->sort(fn(Player $a, Player $b): int => $a->jersey <=> $b->jersey);
        $this->assertSame(0, $empty->count, "sorting an empty set is a no-op");

        $single = new FaultingSet($this->team, $this->roster);
        $single->setSet(new Set([$this->player(7)]));
        $single->sort(fn(Player $a, Player $b): int => $a->jersey <=> $b->jersey);
        $this->assertSame([7], $this->jerseys($single), "sorting a singleton leaves it intact");
    }
}
