<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\SortDescriptor;

/**
 * @property string|null $name
 * @property string|null $modified
 */
class Frame extends ManagedObject
{
}

final class Lap extends Frame
{
}

/**
 * @property string|null $label
 */
final class Step extends ManagedObject
{
}

final class SQLPaginatedSubentityTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $modified = new AttributeDescription();
        $modified->name = "modified";
        $modified->type = AttributeType::string;

        $steps = new RelationshipDescription();
        $steps->name = "steps";
        $steps->lazyDestinationEntityName = "Step";
        $steps->lazyInverseRelationshipName = "frame";
        $steps->isToMany = true;

        $frame = new EntityDescription();
        $frame->name = "Frame";
        $frame->managedObjectClassName = Frame::class;
        $frame->properties = new ArrayClass([$name, $modified, $steps]);

        $lap = new EntityDescription();
        $lap->name = "Lap";
        $lap->managedObjectClassName = Lap::class;
        $lap->superentity = $frame;
        $frame->subentities = new ArrayClass([$lap]);

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $frameOfStep = new RelationshipDescription();
        $frameOfStep->name = "frame";
        $frameOfStep->lazyDestinationEntityName = "Frame";
        $frameOfStep->lazyInverseRelationshipName = "steps";
        $frameOfStep->maxCount = 1;

        $step = new EntityDescription();
        $step->name = "Step";
        $step->managedObjectClassName = Step::class;
        $step->properties = new ArrayClass([$label, $frameOfStep]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$frame, $lap, $step]);
        return $model;
    }

    /** @throws Exception */
    private function seed(): void
    {
        $context = $this->bootstrap(self::model());
        foreach (["early" => "2026-01-01", "late" => "2026-06-01", "latest" => "2026-09-01"] as $name => $modified) {
            $frame = new Frame($context);
            $frame->name = $name;
            $frame->modified = $modified;
            $step = new Step($context);
            $step->label = "$name step";
            $step->setValueForKey($frame, "frame");
        }
        foreach (["old lap" => "2026-02-01", "lap" => "2026-12-01"] as $name => $modified) {
            $lap = new Lap($context);
            $lap->name = $name;
            $lap->modified = $modified;
        }
        $context->save();
    }

    /** @param class-string<Frame> $frameClass */
    private function request(string $frameClass): FetchRequest
    {
        $request = $frameClass::fetchRequest();
        $request->includesSubentities = false;
        $request->predicate = Predicate::format("modified > %@", new ArrayClass(["2026-03-01"]));
        $request->serialization = new Dictionary(["name" => true, "steps" => new Dictionary(["label" => true])]);
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("modified", false)]);
        $request->fetchLimit = 5;
        return $request;
    }

    /** @throws Exception */
    public function testAPageOfAnEntityWithSubentitiesFiltersByItsPredicate(): void
    {
        $this->seed();
        $names = $this->freshContext(self::model())->fetch($this->request(Frame::class))->map(fn(Frame $frame): ?string => $frame->name);
        $this->assertSame(["latest", "late"], $names->array);
    }

    /** @throws Exception */
    public function testAPageOfASubentityHoldsTheSubentityRows(): void
    {
        $this->seed();
        $names = $this->freshContext(self::model())->fetch($this->request(Lap::class))->map(fn(Frame $frame): ?string => $frame->name);
        $this->assertSame(["lap"], $names->array);
    }
}
