<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;

final class Tome2 extends ManagedObject
{
}

final class Scribe2 extends ManagedObject
{
}

/**
 * Regression test: a to-one relationship that is present and UNCHANGED across both model
 * versions, on an entity that is a transform mapping because some OTHER attribute changed, must
 * keep its foreign-key column and data. (Suspected regression from the obsolete-FK-cleanup
 * branch added for the to-one -> many-to-many transition.)
 *
 * Tome2 -author-> Scribe2 (to-one, inverse to-many "tomes"), unchanged between v1 and v2. In v2
 * Tome2 additionally changes an unrelated attribute's type (integer32 -> integer64), which makes
 * the entity a transform mapping. The authorID FK column and its value must survive.
 */
final class SQLStoreMigratorUnchangedToOneTest extends SQLMigrationTestCase
{
    private static function attribute(string $name, AttributeType $type): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = $name;
        $attribute->type = $type;
        $attribute->isOptional = true;
        return $attribute;
    }

    private static function model(AttributeType $pagesType): ManagedObjectModel
    {
        $author = new RelationshipDescription();
        $author->name = "author";
        $author->lazyDestinationEntityName = "Scribe2";
        $author->lazyInverseRelationshipName = "tomes";
        $author->maxCount = 1;

        $tome = new EntityDescription();
        $tome->name = "Tome2";
        $tome->managedObjectClassName = Tome2::class;
        $tome->properties = new ArrayClass([
            self::attribute("title", AttributeType::string),
            self::attribute("pages", $pagesType), // this is what changes v1 -> v2
            $author,
        ]);

        $tomes = new RelationshipDescription();
        $tomes->name = "tomes";
        $tomes->lazyDestinationEntityName = "Tome2";
        $tomes->lazyInverseRelationshipName = "author";
        $tomes->isToMany = true;

        $scribe = new EntityDescription();
        $scribe->name = "Scribe2";
        $scribe->managedObjectClassName = Scribe2::class;
        $scribe->properties = new ArrayClass([
            self::attribute("name", AttributeType::string),
            $tomes,
        ]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$tome, $scribe]);
        return $model;
    }

    public function testUnchangedToOneKeepsItsForeignKeyColumnAcrossATransform(): void
    {
        // v1: pages is integer32.
        $context = $this->bootstrap(self::model(AttributeType::integer32));
        $scribe = new Scribe2($context);
        $scribe->name = "Borges";
        $tome = new Tome2($context);
        $tome->title = "Ficciones";
        $tome->author = $scribe;
        $context->save();

        $this->assertTrue($this->hasColumn("Tome2", "authorID"), "precondition: FK column exists in v1");
        $authorIdBefore = $this->columnValues("Tome2", "authorID");
        $this->assertNotEmpty($authorIdBefore[0] ?? "", "precondition: the FK column holds the related author's id");

        // v2: pages becomes integer64 -> Tome2 is a transform mapping, but the author to-one is unchanged.
        $this->migrateTo(self::model(AttributeType::integer64));

        $this->assertTrue(
            $this->hasColumn("Tome2", "authorID"),
            "an unchanged to-one relationship must keep its foreign-key column through a transform",
        );
        $this->assertSame(
            $authorIdBefore,
            $this->columnValues("Tome2", "authorID"),
            "the foreign-key value must survive (it must not be dropped as if obsolete)",
        );
    }
}
