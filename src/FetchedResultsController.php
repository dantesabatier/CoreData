<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ObjectClass;

/**
 * A controller that you use to manage the results of a Core Data fetch request and to display data to the user.
 * @template ResultType
 */
class FetchedResultsController extends ObjectClass
{
    /** @var FetchedResultsControllerDelegate|null The object that is notified when the fetched results changed. If you do not specify a delegate, the controller does not track changes to managed objects associated with its managed object context. */
    public ?FetchedResultsControllerDelegate $delegate = null;
    /** @var ArrayClass<ResultType>|null The results of the fetch. The value of the property is nil if performFetch() hasn't been called. The results array only includes instances of the entity specified by the fetch request (fetchRequest) and that match its predicate. (If the fetch request has no predicate, then the results array includes all instances of the entity specified by the fetch request.) The results array reflects the in-memory state of managed objects in the controller's managed object context, not their state in the persistent store. The returned array does not, however, update as managed objects are inserted, modified, or deleted. */
    public ?ArrayClass $fetchedObjects = null;
    /** @var ArrayClass<FetchedResultsSectionInfo>|null The sections for the fetch results. The objects in the sections array implement the FetchedResultsSectionInfo protocol. */
    public ?ArrayClass $sections = null;
    /** @var ArrayClass<string> The array of section index titles. The default implementation returns the array created by calling sectionIndexTitle(forSectionName:) on all the known sections. You should override this method if you want to return a different array for the section index. You only need this method if you use a section index. */
    public ArrayClass $sectionIndexTitles;

    /**
     * Returns a fetch request controller initialized using the given arguments.
     * @param FetchRequest<ResultType> $fetchRequest The fetch request used to get the objects. The fetch request must have at least one sort descriptor. If the controller generates sections, the first sort descriptor in the array is used to group the objects into sections; its key must either be the same as sectionNameKeyPath or the relative ordering using its key must match that using sectionNameKeyPath. You must not modify fetchRequest after invoking this method. For example, you must not change its predicate or the sort orderings.
     * @param ManagedObjectContext $managedObjectContext The managed object against which fetchRequest is executed.
     * @param string|null $sectionNameKeyPath A key path on result objects that returns the section name. Pass nil to indicate that the controller should generate a single section. The section name is used to pre-compute the section information. If this key path is not the same as that specified by the first sort descriptor in fetchRequest, they must generate the same relative orderings. For example, the first sort descriptor in fetchRequest might specify the key for a persistent property; sectionNameKeyPath might specify a key for a transient property derived from the persistent property.
     * @param string|null $cacheName The name of the cache file the receiver should use. Pass nil to prevent caching. Pre-computed section info is cached to a private directory under this name. If Core Data finds a cache stored with this name, it is checked to see if it matches the fetchRequest. If it does, the cache is loaded directly—this avoids the overhead of computing the section and index information. If the cached information doesn't match the request, the cache is deleted and recomputed when the fetch happens.
     */
    public function __construct(public readonly FetchRequest $fetchRequest, public readonly ManagedObjectContext $managedObjectContext, public readonly ?string $sectionNameKeyPath = null, public readonly ?string $cacheName = null)
    {
    }

    /**
     * Executes the controller's fetch request.
     * After you execute this method, access the controller's fetched objects using the fetchedObjects property.
     * If you specify a value for the sectionNameKeyPath parameter when you initialize the fetched results controller, the fetch request must include a sort descriptor for the corresponding key path; otherwise, the fetch fails.
     * @throws Exception
     */
    public function performFetch(): void
    {
    }

    /**
     * Deletes the cached section information with the given name.
     * @param string|null $name The name of the cache file to delete. If name is nil, deletes all cache files.
     */
    public static function deleteCache(?string $name): void
    {
    }

    /**
     * Returns the object at the given index path in the fetch results.
     * @param mixed $indexPath An index path in the fetch results. If indexPath does not describe a valid index path in the fetch results, an exception is raised.
     * @return ResultType The object at a given index path in the fetch results.
     */
    public function object(mixed $indexPath)
    {
        return $this->fetchedObjects?->elementAt($indexPath->row);
    }

    /**
     * Returns the index path of a given object.
     * @param ResultType $object An object in the receiver's fetch results.
     * @return mixed The index path of object in the receiver's fetch results, or nil if object could not be found.
     */
    public function indexPath(/** @noinspection PhpUnusedParameterInspection */ mixed $object): mixed
    {
        return null;
    }

    /**
     * Returns the section number for a given section title and index in the section index.
     * @param string $title The title of a section
     * @param int $at The index of a section.
     * @return int The section number for the given section title and index in the section index
     */
    public function section(/** @noinspection PhpUnusedParameterInspection */ string $title, int $at): int
    {
        return 0;
    }

    /**
     * Returns the corresponding section index entry for a given section name.
     * The default implementation returns the capitalized first letter of the section name. You should override this method if you need a different way to convert from a section name to its name in the section index.
     * @param string $sectionName The name of a section.
     * @return string|null The section index entry corresponding to the section with name sectionName.
     */
    public function sectionIndexTitle(/** @noinspection PhpUnusedParameterInspection */ string $sectionName): ?string
    {
        return null;
    }
}
