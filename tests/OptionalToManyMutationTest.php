<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

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
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;

final class OptionalToManyOwner extends ManagedObject
{
}

final class OptionalToManyMember extends ManagedObject
{
}

/**
 * The generated to-many mutators have to cope with a relationship that reads as null.
 *
 * An optional relationship is nullable by contract, so valueForKey() answers null for a to-many
 * that nothing has materialised yet — and isOptional defaults to true, so that is the ordinary
 * case, not an edge one. The mutators used to annotate the result as a plain FaultingSet and
 * call straight into it, which made every one of them a fatal error on a fresh object:
 *
 *     Call to a member function containsElement() on null
 *
 * A null relationship holds nothing, so the mutators never blindly invent a set to write into.
 * The additive ones make one exception, and only where the model grants it: isOptional is the
 * permission for the relationship to be absent, which is what makes inserting the first object
 * a legal transition from absent to present. So add<Key>Object()/add<Key>() materialize the set
 * when the relationship is optional, and a mandatory one reading as null stays null — that is
 * an invalid state, not something to paper over. remove<Key>()/intersect<Key>() materialize
 * nothing either way: there is nothing to take out of a relationship that is not there.
 *
 * Raya reaches all of this through Task::willSave(), which calls addUsersObject()/
 * addMachinesObject()/addShiftsObject() on a Production.
 *
 * The crash is in the object graph and needs no save, but the stack still needs a store: a
 * coordinator with none cannot hand out object IDs when the objects are registered.
 */
final class OptionalToManyMutationTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-optional-tomany-test-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    /**
     * Owner <->> Member, with the to-many left optional — the default, and the configuration
     * that produced the crash.
     */
    private function makeContext(bool $optionalToMany = true): ManagedObjectContext
    {
        $ownerName = new AttributeDescription();
        $ownerName->name = "name";
        $ownerName->type = AttributeType::string;
        $ownerName->isOptional = true;

        $members = new RelationshipDescription();
        $members->name = "members";
        $members->lazyDestinationEntityName = "OptionalToManyMember";
        $members->lazyInverseRelationshipName = "owner";
        $members->isToMany = true;
        $members->isOptional = $optionalToMany;

        $owner = new EntityDescription();
        $owner->name = "OptionalToManyOwner";
        $owner->managedObjectClassName = OptionalToManyOwner::class;
        $owner->properties = new ArrayClass([$ownerName, $members]);

        $memberName = new AttributeDescription();
        $memberName->name = "name";
        $memberName->type = AttributeType::string;
        $memberName->isOptional = true;

        $ownerRef = new RelationshipDescription();
        $ownerRef->name = "owner";
        $ownerRef->lazyDestinationEntityName = "OptionalToManyOwner";
        $ownerRef->lazyInverseRelationshipName = "members";
        $ownerRef->isOptional = true;

        $member = new EntityDescription();
        $member->name = "OptionalToManyMember";
        $member->managedObjectClassName = OptionalToManyMember::class;
        $member->properties = new ArrayClass([$memberName, $ownerRef]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$owner, $member]);

        $coordinator = new PersistentStoreCoordinator($model);
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * @return array{0: OptionalToManyOwner, 1: OptionalToManyMember}
     */
    private function makePair(ManagedObjectContext $context): array
    {
        $owner = new OptionalToManyOwner($context);
        $owner->name = "owner";
        $member = new OptionalToManyMember($context);
        $member->name = "member";
        return [$owner, $member];
    }

    public function testAddObjectOnANullOptionalToManyMaterializesIt(): void
    {
        $context = $this->makeContext();
        [$owner, $member] = $this->makePair($context);

        $owner->addMembersObject($member);

        $this->assertTrue($owner->members->containsElement($member), "optional grants the absent-to-present transition");
        $this->assertSame($owner, $member->owner, "and the inverse was set");
    }

    public function testAddOnANullOptionalToManyMaterializesIt(): void
    {
        $context = $this->makeContext();
        [$owner, $member] = $this->makePair($context);

        $owner->addMembers(new Set([$member]));

        $this->assertTrue($owner->members->containsElement($member), "optional grants the absent-to-present transition");
    }

    /**
     * Once the relationship exists — the ordinary case, after a fetch or an explicit set — the
     * mutators do their work.
     */
    public function testAddObjectOnAMaterialisedToManyAdds(): void
    {
        $context = $this->makeContext();
        [$owner, $member] = $this->makePair($context);
        $owner->setValueForKey(new Set(), "members");

        $owner->addMembersObject($member);

        $this->assertTrue($owner->members->containsElement($member), "the added object is in the relationship");
        $this->assertSame($owner, $member->owner, "and the inverse was set");
    }

    public function testRemoveObjectOnANullOptionalToManyDoesNothing(): void
    {
        $context = $this->makeContext();
        [$owner, $member] = $this->makePair($context);

        $owner->removeMembersObject($member);

        $this->assertNull($owner->members, "a null relationship stays null");
    }

    public function testRemoveOnANullOptionalToManyDoesNothing(): void
    {
        $context = $this->makeContext();
        [$owner, $member] = $this->makePair($context);

        $owner->removeMembers(new Set([$member]));

        $this->assertNull($owner->members, "a null relationship stays null");
    }

    public function testIntersectOnANullOptionalToManyReturnsNull(): void
    {
        $context = $this->makeContext();
        [$owner, $member] = $this->makePair($context);

        $result = $owner->intersectMembers(new Set([$member]));

        $this->assertNull($result, "there is no relationship to intersect and none is invented");
    }

    /**
     * The mutators still have to behave once the relationship exists.
     */
    public function testRemoveObjectAfterAddLeavesTheRelationshipEmpty(): void
    {
        $context = $this->makeContext();
        [$owner, $member] = $this->makePair($context);
        $owner->setValueForKey(new Set(), "members");

        $owner->addMembersObject($member);
        $owner->removeMembersObject($member);

        $this->assertFalse($owner->members->containsElement($member), "the object was removed again");
        $this->assertNull($member->owner, "and the inverse was cleared");
    }
}
