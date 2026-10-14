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
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\SortDescriptor;

/**
 * @property string $name
 * @property Set<GraphVolume> $volumes
 */
final class GraphShelf extends ManagedObject
{
}

/**
 * @property string $title
 * @property GraphShelf $shelf
 */
final class GraphVolume extends ManagedObject
{
}

/**
 * A page must return the same graph an unpaginated fetch returns; only how many objects arrive may differ.
 *
 * LIMIT counts rows, not objects, and a fetch that serializes a to-many carries one row per related object, so a page is resolved as a key subquery over the root entity. That subquery takes the predicate with it, and the outer query, the one that hydrates the graph, used to keep only `IN (keys)`: an unpaginated fetch prunes a serialized to-many to the related objects that satisfy a predicate walking it, while a page returned it whole. The predicate is now applied to the outer query too.
 */
final class SQLPaginatedGraphTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $volumes = new RelationshipDescription();
        $volumes->name = "volumes";
        $volumes->lazyDestinationEntityName = "GraphVolume";
        $volumes->lazyInverseRelationshipName = "shelf";
        $volumes->isToMany = true;

        $shelf = new RelationshipDescription();
        $shelf->name = "shelf";
        $shelf->lazyDestinationEntityName = "GraphShelf";
        $shelf->lazyInverseRelationshipName = "volumes";
        $shelf->maxCount = 1;

        $shelfEntity = new EntityDescription();
        $shelfEntity->name = "GraphShelf";
        $shelfEntity->managedObjectClassName = GraphShelf::class;
        $shelfEntity->properties = new ArrayClass([$name, $volumes]);

        $volumeEntity = new EntityDescription();
        $volumeEntity->name = "GraphVolume";
        $volumeEntity->managedObjectClassName = GraphVolume::class;
        $volumeEntity->properties = new ArrayClass([$title, $shelf]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$shelfEntity, $volumeEntity]);
        return $model;
    }

    /**
     * Three shelves: two hold a volume titled "Match" among others, one holds none.
     *
     * @throws Exception
     */
    private function seed(): void
    {
        $context = $this->bootstrap(self::model());
        foreach (["A" => ["Match", "Other", "Third"], "B" => ["Match", "Fourth"], "C" => ["Fifth"]] as $shelfName => $titles) {
            $shelf = new GraphShelf($context);
            $shelf->name = $shelfName;
            foreach ($titles as $volumeTitle) {
                $volume = new GraphVolume($context);
                $volume->title = $volumeTitle;
                $volume->setValueForKey($shelf, "shelf");
            }
        }
        $context->save();
    }

    private function request(int $limit = 0, int $offset = 0): FetchRequest
    {
        $request = GraphShelf::fetchRequest();
        $request->predicate = Predicate::format("volumes.title == %@", new ArrayClass(["Match"]));
        $request->serialization = new Dictionary(["name" => true, "volumes" => new Dictionary(["title" => true])]);
        $request->sortDescriptors = new ArrayClass([new SortDescriptor("name", true)]);
        $request->fetchLimit = $limit;
        $request->fetchOffset = $offset;
        return $request;
    }

    /**
     * Each shelf as its name and the titles it was hydrated with, in a fresh context so nothing a previous fetch materialized leaks in.
     *
     * @return list<string>
     * @throws Exception
     */
    private function graph(FetchRequest $request): array
    {
        $context = $this->freshContext(self::model());
        return $this->describe($context, $request);
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    private function describe(ManagedObjectContext $context, FetchRequest $request): array
    {
        // A foreach rather than map(): map() on a Set would deduplicate titles, and the order of the shelves is part of what is compared.
        $rows = [];
        foreach ($context->fetch($request) as $shelf) {
            $titles = [];
            foreach ($shelf->volumes as $volume) {
                $titles[] = $volume->title;
            }
            sort($titles);
            $rows[] = $shelf->name . ": " . implode(", ", $titles);
        }
        return $rows;
    }

    /**
     * The behavior a page has to match: the predicate selects the shelves and prunes the volumes they are hydrated with.
     *
     * @throws Exception
     */
    public function testUnpaginatedFetchPrunesTheToManyToTheMatchingObjects(): void
    {
        $this->seed();

        $this->assertSame(["A: Match", "B: Match"], $this->graph($this->request()));
    }

    /**
     * Walking the pages yields the same graph as the unpaginated fetch, one shelf per page: the limit counts shelves, not the rows their volumes take up, and the volumes stay pruned.
     *
     * @throws Exception
     */
    public function testPagesReturnTheSameGraphAsAnUnpaginatedFetch(): void
    {
        $this->seed();

        $this->assertSame(["A: Match"], $this->graph($this->request(1)));
        $this->assertSame(["B: Match"], $this->graph($this->request(1, 1)));
        $this->assertSame([], $this->graph($this->request(1, 2)));
    }

    /**
     * A page larger than the result returns every matching shelf, pruned exactly as without a limit.
     *
     * @throws Exception
     */
    /**
     * A page resolves its keys in a subquery that renames the root table, so a collection operator in the predicate,
     * which becomes a correlated subquery, has to correlate with that alias. It correlated with the table's own name,
     * which only exists in the outer query, and the page failed with an unknown column.
     *
     * @throws Exception
     */
    public function testAPageFiltersByACollectionOperator(): void
    {
        $this->seed();
        $names = function (int $limit): array {
            $request = GraphShelf::fetchRequest();
            $request->predicate = Predicate::format("volumes.@count > 1");
            $request->serialization = new Dictionary(["name" => true]);
            $request->sortDescriptors = new ArrayClass([new SortDescriptor("name", true)]);
            $request->fetchLimit = $limit;
            return $this->freshContext(self::model())->fetch($request)->map(fn(GraphShelf $shelf): string => $shelf->name)->array;
        };

        $this->assertSame(["A", "B"], $names(0));
        $this->assertSame(["A"], $names(1));
    }

    public function testAPageCoveringTheWholeResultMatchesTheUnpaginatedFetch(): void
    {
        $this->seed();

        $this->assertSame($this->graph($this->request()), $this->graph($this->request(10)));
    }
}
