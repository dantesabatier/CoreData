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
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;

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

/**
 * @property string $title
 * @property SubentityPolygon|null $polygon
 */
final class SubentityDrawing extends ManagedObject
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

        $drawings = new RelationshipDescription();
        $drawings->name = "drawings";
        $drawings->lazyDestinationEntityName = "Drawing";
        $drawings->lazyInverseRelationshipName = "polygon";
        $drawings->isToMany = true;
        $polygon->properties = new ArrayClass([$drawings]);

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

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $drawingPolygon = new RelationshipDescription();
        $drawingPolygon->name = "polygon";
        $drawingPolygon->lazyDestinationEntityName = "Polygon";
        $drawingPolygon->lazyInverseRelationshipName = "drawings";

        $drawing = new EntityDescription();
        $drawing->name = "Drawing";
        $drawing->managedObjectClassName = SubentityDrawing::class;
        $drawing->properties = new ArrayClass([$title, $drawingPolygon]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$shape, $drawing]);
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
        $sketch = new SubentityDrawing($context);
        $sketch->title = "sketch";
        $sketch->polygon = $square;
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

    /** @throws Exception */
    public function testAJoinToAConcreteEntityIncludesItsSubentities(): void
    {
        $context = $this->freshContext(self::model());
        $titles = [];
        $context->performBlockAndWait(function () use ($context, &$titles): void {
            $request = new FetchRequest("Drawing");
            $request->predicate = Predicate::format("polygon.name == %@", new ArrayClass(["square"]));
            $titles = $context->fetch($request)->map(fn(SubentityDrawing $drawing): string => $drawing->title)->array;
        });

        $this->assertSame(["sketch"], $titles);
    }
}
