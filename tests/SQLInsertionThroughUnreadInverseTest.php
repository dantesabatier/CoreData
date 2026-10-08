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
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Set;

/**
 * @property string $name
 * @property Set<SQLBasketLine>|null $lines
 */
final class SQLBasket extends ManagedObject
{
}

/**
 * @property string $name
 * @property SQLBasket|null $basket
 * @property SQLLineKind|null $kind
 */
final class SQLBasketLine extends ManagedObject
{
}

/**
 * @property string $name
 * @property Set<SQLBasketLine>|null $lines
 */
final class SQLLineKind extends ManagedObject
{
}

/**
 * A new object linked to a saved one whose inverse has not been read is still saved.
 *
 * Linking a new object to a saved one announces the insertion on the saved object's inverse, and
 * that announcement is how the context learns the new object has to be written. Leaving an unread
 * inverse as a fault must not silence it: a snapshot that adds several new lines to a basket, each
 * of a kind whose lines were never read, used to save only the first one. Surfaced in Raya, where
 * generating the productions of a product made of several designs saved only the first production.
 */
final class SQLInsertionThroughUnreadInverseTest extends SQLMigrationTestCase
{
    /** SQLBasket 1 <-> * SQLBasketLine * <-> 1 SQLLineKind. */
    private static function makeModel(): ManagedObjectModel
    {
        $basketName = new AttributeDescription();
        $basketName->name = "name";
        $basketName->type = AttributeType::string;

        $basketLines = new RelationshipDescription();
        $basketLines->name = "lines";
        $basketLines->lazyDestinationEntityName = "SQLBasketLine";
        $basketLines->lazyInverseRelationshipName = "basket";
        $basketLines->isToMany = true;
        $basketLines->isOptional = true;

        $basket = new EntityDescription();
        $basket->name = "SQLBasket";
        $basket->managedObjectClassName = SQLBasket::class;
        $basket->properties = new ArrayClass([$basketName, $basketLines]);

        $lineName = new AttributeDescription();
        $lineName->name = "name";
        $lineName->type = AttributeType::string;

        $lineBasket = new RelationshipDescription();
        $lineBasket->name = "basket";
        $lineBasket->lazyDestinationEntityName = "SQLBasket";
        $lineBasket->lazyInverseRelationshipName = "lines";
        $lineBasket->isOptional = true;

        $lineKind = new RelationshipDescription();
        $lineKind->name = "kind";
        $lineKind->lazyDestinationEntityName = "SQLLineKind";
        $lineKind->lazyInverseRelationshipName = "lines";
        $lineKind->isOptional = true;

        $line = new EntityDescription();
        $line->name = "SQLBasketLine";
        $line->managedObjectClassName = SQLBasketLine::class;
        $line->properties = new ArrayClass([$lineName, $lineBasket, $lineKind]);

        $kindName = new AttributeDescription();
        $kindName->name = "name";
        $kindName->type = AttributeType::string;

        $kindLines = new RelationshipDescription();
        $kindLines->name = "lines";
        $kindLines->lazyDestinationEntityName = "SQLBasketLine";
        $kindLines->lazyInverseRelationshipName = "kind";
        $kindLines->isToMany = true;
        $kindLines->isOptional = true;

        $kind = new EntityDescription();
        $kind->name = "SQLLineKind";
        $kind->managedObjectClassName = SQLLineKind::class;
        $kind->properties = new ArrayClass([$kindName, $kindLines]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$basket, $line, $kind]);
        return $model;
    }

    /** @throws Exception */
    public function testASnapshotAddingNewLinesOfUnreadKindsSavesThemAll(): void
    {
        $seed = $this->bootstrap(self::makeModel());
        $basket = new SQLBasket($seed);
        $basket->name = "basket";
        foreach (["first", "second"] as $name) {
            $kind = new SQLLineKind($seed);
            $kind->name = $name;
        }
        $seed->save();

        $context = $this->freshContext(self::makeModel());
        $loadedBasket = $context->fetch(SQLBasket::fetchRequest())->first;
        $this->assertNotNull($loadedBasket);
        $this->assertSame(0, $loadedBasket->lines?->count ?? -1, "precondition: the basket's lines have been read, and there are none");
        /** @var ArrayClass<SQLLineKind> $kinds */
        $kinds = $context->fetch(SQLLineKind::fetchRequest());
        $first = $kinds->first(fn(SQLLineKind $kind): bool => $kind->name === "first");
        $second = $kinds->first(fn(SQLLineKind $kind): bool => $kind->name === "second");
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->hasFaultForRelationshipNamed("lines"), "precondition: the kinds' lines have not been read");

        // Each line names its basket as well, as a client sending back the object it was built from does.
        $basketReference = ["objectID" => $loadedBasket->objectID->referenceObject];
        $loadedBasket->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "lines" => [
                ["name" => "first", "basket" => $basketReference, "kind" => ["objectID" => $first->objectID->referenceObject]],
                ["name" => "second", "basket" => $basketReference, "kind" => ["objectID" => $second->objectID->referenceObject]],
            ]
        ]));
        $context->save();

        $reloaded = $this->freshContext(self::makeModel());
        $this->assertSame(2, $reloaded->fetch(SQLBasketLine::fetchRequest())->count, "both lines reach the database");
        $this->assertSame(2, $reloaded->fetch(SQLBasket::fetchRequest())->first?->lines?->count ?? -1, "and the basket reads back both");
    }

    /**
     * Each kind already holds a saved line in another basket, so its unread inverse has members that announcing the insertion must not lose.
     *
     * @throws Exception
     */
    public function testAnnouncingTheInsertionKeepsTheStoredMembersOfAnUnreadInverse(): void
    {
        $seed = $this->bootstrap(self::makeModel());
        $basket = new SQLBasket($seed);
        $basket->name = "basket";
        $other = new SQLBasket($seed);
        $other->name = "other";
        foreach (["first", "second"] as $name) {
            $kind = new SQLLineKind($seed);
            $kind->name = $name;
            $line = new SQLBasketLine($seed);
            $line->name = "stored $name";
            $line->basket = $other;
            $line->kind = $kind;
        }
        $seed->save();

        $context = $this->freshContext(self::makeModel());
        $loadedBasket = $context->fetch(SQLBasket::fetchRequest())->first(fn(SQLBasket $basket): bool => $basket->name === "basket");
        $this->assertNotNull($loadedBasket);
        $this->assertSame(0, $loadedBasket->lines?->count ?? -1, "precondition: the basket's lines have been read, and there are none");
        /** @var ArrayClass<SQLLineKind> $kinds */
        $kinds = $context->fetch(SQLLineKind::fetchRequest());
        $first = $kinds->first(fn(SQLLineKind $kind): bool => $kind->name === "first");
        $second = $kinds->first(fn(SQLLineKind $kind): bool => $kind->name === "second");
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->hasFaultForRelationshipNamed("lines"), "precondition: the kinds' lines have not been read");

        // Each line names its basket as well, as a client sending back the object it was built from does.
        $basketReference = ["objectID" => $loadedBasket->objectID->referenceObject];
        $loadedBasket->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "lines" => [
                ["name" => "first", "basket" => $basketReference, "kind" => ["objectID" => $first->objectID->referenceObject]],
                ["name" => "second", "basket" => $basketReference, "kind" => ["objectID" => $second->objectID->referenceObject]],
            ]
        ]));
        $this->assertTrue($first->hasFaultForRelationshipNamed("lines"), "announcing the insertion leaves the kind's lines unread");
        $context->save();
        $this->assertSame(2, $first->lines?->count ?? -1, "reading them after the save finds the stored line and the new one");

        $reloaded = $this->freshContext(self::makeModel());
        $this->assertSame(4, $reloaded->fetch(SQLBasketLine::fetchRequest())->count, "both new lines reach the database next to the stored ones");
        $this->assertSame(2, $reloaded->fetch(SQLBasket::fetchRequest())->first(fn(SQLBasket $basket): bool => $basket->name === "basket")?->lines?->count ?? -1, "the basket reads back both");
        $reloadedKinds = $reloaded->fetch(SQLLineKind::fetchRequest());
        $this->assertTrue($reloadedKinds->allSatisfy(fn(SQLLineKind $kind): bool => $kind->lines?->count === 2), "and each kind keeps its stored line next to the new one");
    }

    /**
     * Before the save, reading the unread inverse finds the stored members and the new one: the announcement did not leave it holding only the new member.
     *
     * @throws Exception
     */
    public function testReadingAnUnreadInverseBeforeTheSaveKeepsItsStoredMembers(): void
    {
        $seed = $this->bootstrap(self::makeModel());
        $kind = new SQLLineKind($seed);
        $kind->name = "kind";
        foreach (["first", "second"] as $name) {
            $line = new SQLBasketLine($seed);
            $line->name = $name;
            $line->kind = $kind;
        }
        $basket = new SQLBasket($seed);
        $basket->name = "basket";
        $seed->save();

        $context = $this->freshContext(self::makeModel());
        $loadedBasket = $context->fetch(SQLBasket::fetchRequest())->first;
        $loadedKind = $context->fetch(SQLLineKind::fetchRequest())->first;
        $this->assertNotNull($loadedBasket);
        $this->assertNotNull($loadedKind);
        $this->assertTrue($loadedKind->hasFaultForRelationshipNamed("lines"), "precondition: the kind's lines have not been read");

        $loadedBasket->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "lines" => [
                ["name" => "third", "kind" => ["objectID" => $loadedKind->objectID->referenceObject]],
            ]
        ]));

        $this->assertSame(3, $loadedKind->lines?->count ?? -1, "the kind reads its two stored lines and the new one");
    }
}
