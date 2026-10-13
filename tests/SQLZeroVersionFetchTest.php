<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * @property string $name
 */
final class TestGear extends ManagedObject
{
}

/**
 * Tests that a fetched row whose version column holds zero is materialized as a stable object.
 *
 * A row written before versions were tracked can carry version 0. The fetch decided whether a snapshot was
 * complete by the truthiness of its version, so such a row came back flagged as a fault although its values
 * were all there: the first read fired the fault again, refetching the row and resetting its relationships.
 */
final class SQLZeroVersionFetchTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $gear = new EntityDescription();
        $gear->name = "TestGear";
        $gear->managedObjectClassName = TestGear::class;
        $gear->properties = new ArrayClass([$name]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$gear]);
        return $model;
    }

    /** @throws Exception */
    private function fetchFirst(ManagedObjectContext $context): ManagedObject
    {
        $fetched = null;
        $context->performBlockAndWait(function () use ($context, &$fetched): void {
            $request = new FetchRequest("TestGear");
            $request->serialization = new Dictionary(["name" => AttributeType::string]);
            $fetched = $context->fetch($request)->first;
        });
        return $fetched;
    }

    /** @throws Exception */
    public function testARowWithVersionZeroIsFetchedAsAStableObject(): void
    {
        $context = $this->bootstrap(self::model());
        $gear = new TestGear($context);
        $gear->name = "sprocket";
        $context->save();
        $this->pdo->exec(sprintf("UPDATE `%s`.`TestGear` SET `version` = 0", static::DATABASE_NAME));

        $fetched = $this->fetchFirst($this->freshContext(self::model()));

        $this->assertSame(0, $fetched->version, "the row really does carry version 0");
        $this->assertFalse($fetched->isFault, "a complete snapshot materializes the object, whatever its version");
    }
}
