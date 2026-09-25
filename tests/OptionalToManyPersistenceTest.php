<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
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
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property Set<OptionalPersistenceChild> $children
 * @property string $name
 * @method void addChildrenObject(OptionalPersistenceChild $object)
 * @method void removeChildrenObject(OptionalPersistenceChild $object)
 * @method void addChildren(Set<OptionalPersistenceChild> $objects)
 * @method void removeChildren(Set<OptionalPersistenceChild> $objects)
 * @method Set<OptionalPersistenceChild> intersectChildren(Set<OptionalPersistenceChild> $objects)
 * @method void setChildren(Set<OptionalPersistenceChild> $objects)
 */
final class OptionalPersistenceParent extends ManagedObject
{
}

/**
 * @property string $name
 * @property OptionalPersistenceParent|null $parent
 */
final class OptionalPersistenceChild extends ManagedObject
{
}

/**
 * Adding to an absent optional to-many, through to the store.
 *
 * isOptional on a to-many is the permission for the relationship to be absent, and that
 * permission is exactly what makes inserting the first object a legal transition from absent
 * to present. The additive mutators therefore materialize the set when — and only when — the
 * relationship is optional; a mandatory one reading as null is an invalid state they refuse to
 * paper over.
 *
 * Before that, add<Key>Object() on a fresh optional to-many silently did nothing: the link
 * never reached the store, the collection stayed at 0 and the to-one inverse stayed null, with
 * no error to show for it. Surfaced in Singularity, whose ModelMap/EntityMap/PropertyMap
 * relationships are declared optional.
 */
final class OptionalToManyPersistenceTest extends TestCase
{
    private URL $storeURL;

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");
    }

    /** @throws Exception */
    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    private static function makeModel(bool $optionalToMany): ManagedObjectModel
    {
        $parentName = new AttributeDescription();
        $parentName->name = "name";
        $parentName->type = AttributeType::string;

        $children = new RelationshipDescription();
        $children->name = "children";
        $children->lazyDestinationEntityName = "OptionalPersistenceChild";
        $children->lazyInverseRelationshipName = "parent";
        $children->isToMany = true;
        $children->isOptional = $optionalToMany;

        $parent = new EntityDescription();
        $parent->name = "OptionalPersistenceParent";
        $parent->managedObjectClassName = OptionalPersistenceParent::class;
        $parent->properties = new ArrayClass([$parentName, $children]);

        $childName = new AttributeDescription();
        $childName->name = "name";
        $childName->type = AttributeType::string;

        $parentRef = new RelationshipDescription();
        $parentRef->name = "parent";
        $parentRef->lazyDestinationEntityName = "OptionalPersistenceParent";
        $parentRef->lazyInverseRelationshipName = "children";
        $parentRef->isOptional = true;

        $child = new EntityDescription();
        $child->name = "OptionalPersistenceChild";
        $child->managedObjectClassName = OptionalPersistenceChild::class;
        $child->properties = new ArrayClass([$childName, $parentRef]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$parent, $child]);
        return $model;
    }

    /** @throws Exception */
    private function makeContext(bool $optionalToMany): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makeModel($optionalToMany));
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /** @throws Exception */
    private function runLinkAndSave(bool $optionalToMany): void
    {
        $context = $this->makeContext($optionalToMany);
        $parent = new OptionalPersistenceParent($context);
        $parent->name = "parent";
        $child = new OptionalPersistenceChild($context);
        $child->name = "child";

        $parent->addChildrenObject($child);

        $this->assertSame(1, $parent->children?->count ?? 0, "the child is in the to-many after the mutator");
        $this->assertSame($parent, $child->parent, "and the to-one inverse was set");

        $context->save();

        /** @var OptionalPersistenceParent|null $fetchedParent */
        $fetchedParent = $this->makeContext($optionalToMany)->fetch(OptionalPersistenceParent::fetchRequest())->first;
        $this->assertNotNull($fetchedParent, "the parent was persisted");
        $this->assertSame(1, $fetchedParent->children?->count ?? 0, "the to-many survived the save");
    }

    /** @throws Exception */
    public function testOptionalToManyPersistsTheLink(): void
    {
        $this->runLinkAndSave(true);
    }

    /**
     * The mandatory case took a different branch in valueForKey() and always worked; it is the
     * control that says the optional one is what changed.
     *
     * @throws Exception
     */
    public function testMandatoryToManyPersistsTheLink(): void
    {
        $this->runLinkAndSave(false);
    }
}
