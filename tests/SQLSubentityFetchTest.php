<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\BatchDeleteRequest;
use Sabatier\CoreData\BatchDeleteRequestResultType;
use Sabatier\CoreData\BatchDeleteResult;
use Sabatier\CoreData\BatchUpdateRequest;
use Sabatier\CoreData\BatchUpdateRequestResultType;
use Sabatier\CoreData\BatchUpdateResult;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * @property string $name
 */
abstract class SubentityShape extends ManagedObject
{
}

class SubentityPolygon extends SubentityShape
{
}

final class SubentitySquare extends SubentityPolygon
{
}

abstract class SubentityRounded extends SubentityShape
{
}

final class SubentityCircle extends SubentityRounded
{
}

final class SQLSubentityFetchTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $shape = new EntityDescription();
        $shape->name = "Shape";
        $shape->managedObjectClassName = SubentityShape::class;
        $shape->isAbstract = true;
        $shape->properties = new ArrayClass([$name]);

        $polygon = new EntityDescription();
        $polygon->name = "Polygon";
        $polygon->managedObjectClassName = SubentityPolygon::class;
        $polygon->superentity = $shape;

        $square = new EntityDescription();
        $square->name = "Square";
        $square->managedObjectClassName = SubentitySquare::class;
        $square->superentity = $polygon;
        $polygon->subentities = new ArrayClass([$square]);

        $rounded = new EntityDescription();
        $rounded->name = "Rounded";
        $rounded->managedObjectClassName = SubentityRounded::class;
        $rounded->isAbstract = true;
        $rounded->superentity = $shape;

        $circle = new EntityDescription();
        $circle->name = "Circle";
        $circle->managedObjectClassName = SubentityCircle::class;
        $circle->superentity = $rounded;
        $rounded->subentities = new ArrayClass([$circle]);

        $shape->subentities = new ArrayClass([$polygon, $rounded]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$shape]);
        return $model;
    }

    /** @throws Exception */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $context = $this->bootstrap(self::model());
        $polygon = new SubentityPolygon($context);
        $polygon->name = "polygon";
        $square = new SubentitySquare($context);
        $square->name = "square";
        $circle = new SubentityCircle($context);
        $circle->name = "circle";
        $context->save();
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    private function names(string $entityName, bool $includesSubentities = true): array
    {
        $context = $this->freshContext(self::model());
        $names = [];
        $context->performBlockAndWait(function () use ($context, $entityName, $includesSubentities, &$names): void {
            $request = new FetchRequest($entityName);
            $request->includesSubentities = $includesSubentities;
            $names = $context->fetch($request)->map(fn(SubentityShape $shape): string => $shape->name)->sort()->array;
        });
        return $names;
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    private function batchDelete(string $entityName, bool $includesSubentities = true): array
    {
        $context = $this->freshContext(self::model());
        $entityNames = [];
        $context->performBlockAndWait(function () use ($context, $entityName, $includesSubentities, &$entityNames): void {
            $request = new FetchRequest($entityName);
            $request->includesSubentities = $includesSubentities;
            $batchDeleteRequest = new BatchDeleteRequest($request);
            $batchDeleteRequest->resultType = BatchDeleteRequestResultType::objectIDs;
            /** @var BatchDeleteResult $result */
            $result = $context->execute($batchDeleteRequest);
            /** @var ArrayClass<ManagedObjectID> $objectIDs */
            $objectIDs = $result->result;
            $entityNames = $objectIDs->map(fn(ManagedObjectID $objectID): string => $objectID->entity->name)->sort()->array;
        });
        return $entityNames;
    }

    /** @throws Exception */
    public function testAConcreteEntityIncludesItsSubentities(): void
    {
        $this->assertSame(["polygon", "square"], $this->names("Polygon"));
    }

    /** @throws Exception */
    public function testAConcreteEntityWithoutSubentitiesHoldsOnlyItsOwnRows(): void
    {
        $this->assertSame(["polygon"], $this->names("Polygon", false));
    }

    /** @throws Exception */
    public function testAnAbstractEntityIncludesOnlyItsOwnSubentities(): void
    {
        $this->assertSame(["circle"], $this->names("Rounded"));
    }

    /** @throws Exception */
    public function testAnAbstractEntityWithoutSubentitiesHoldsNoRows(): void
    {
        $this->assertSame([], $this->names("Rounded", false));
    }

    /** @throws Exception */
    public function testTheRootEntityIncludesTheWholeHierarchy(): void
    {
        $this->assertSame(["circle", "polygon", "square"], $this->names("Shape"));
    }

    /** @throws Exception */
    public function testABatchDeleteRemovesOnlyTheRowsItsFetchSelects(): void
    {
        $this->batchDelete("Square");

        $this->assertSame(["circle", "polygon"], $this->names("Shape"));
    }

    /** @throws Exception */
    public function testABatchDeleteHonorsTheIncludesSubentitiesOfItsFetch(): void
    {
        $this->batchDelete("Polygon", false);

        $this->assertSame(["circle", "square"], $this->names("Shape"));
    }

    /** @throws Exception */
    public function testABatchDeleteReportsEachRowWithItsOwnEntity(): void
    {
        $this->assertSame(["Polygon", "Square"], $this->batchDelete("Polygon"));
    }

    /** @throws Exception */
    public function testABatchUpdateReportsEachRowWithItsOwnEntity(): void
    {
        $context = $this->freshContext(self::model());
        $entityNames = [];
        $context->performBlockAndWait(function () use ($context, &$entityNames): void {
            $request = new BatchUpdateRequest(EntityDescription::entity("Polygon", $context));
            $request->propertiesToUpdate = new Dictionary(["name" => "updated"]);
            $request->resultType = BatchUpdateRequestResultType::objectIDs;
            /** @var BatchUpdateResult $result */
            $result = $context->execute($request);
            /** @var ArrayClass<ManagedObjectID> $objectIDs */
            $objectIDs = $result->result;
            $entityNames = $objectIDs->map(fn(ManagedObjectID $objectID): string => $objectID->entity->name)->sort()->array;
        });

        $this->assertSame(["Polygon", "Square"], $entityNames);
    }
}
