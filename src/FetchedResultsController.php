<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\IndexPath;
use Sabatier\Foundation\Notification;
use Sabatier\Foundation\NotificationCenter;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\capitalize;
use const Sabatier\Foundation\NotFound;

/**
 * A controller that you use to manage the results of a Core Data fetch request and to display data to the user.
 * @template ResultType of ManagedObject
 */
final class FetchedResultsController extends ObjectClass
{
    /** @var FetchedResultsControllerDelegate|null The object that is notified when the fetched results changed. If you do not specify a delegate, the controller does not track changes to managed objects associated with its managed object context. */
    public ?FetchedResultsControllerDelegate $delegate = null;
    /** @var ArrayClass<ResultType> The results of the fetch. The results array only includes instances of the entity specified by the fetch request (fetchRequest) and that match its predicate. (If the fetch request has no predicate, then the results array includes all instances of the entity specified by the fetch request.) The results array reflects the in-memory state of managed objects in the controller's managed object context, not their state in the persistent store. The returned array does not, however, update as managed objects are inserted, modified, or deleted. */
    private(set) ArrayClass $fetchedObjects {
        get => $this->fetchedObjects ??= new ArrayClass();
    }
    /** @var ArrayClass<FetchedResultsSectionInfo> The sections for the fetch results. */
    private(set) ArrayClass $sections {
        get => $this->sections ??= new ArrayClass();
    }
    /** @var ArrayClass<string> The array of section index titles. The default implementation returns the array created by calling {@see sectionIndexTitle()} on all the known sections. You should override this method if you want to return a different array for the section index. You only need this method if you use a section index. */
    public ArrayClass $sectionIndexTitles {
        get => $this->sections->map(fn(FetchedResultsSectionInfo $section): string => (string)$section->indexTitle);
    }

    /**
     * Returns a fetch request controller initialized using the given arguments.
     * @param FetchRequest<ResultType> $fetchRequest The fetch request used to get the objects. The fetch request must have at least one Sort Descriptor. If the controller generates sections, the first sort descriptor in the array is used to group the objects into sections; its key must either be the same as sectionNameKeyPath or the relative ordering using its key must match that using sectionNameKeyPath. You must not modify fetchRequest after invoking this method. For example, you must not change its predicate or the sort orderings.
     * @param ManagedObjectContext $managedObjectContext The managed object against which fetchRequest is executed.
     * @param string|null $sectionNameKeyPath A key path on result objects that returns the section name. Pass null to indicate that the controller should generate a single section. The section name is used to pre-compute the section information. If this key path is different from that specified by the first sort descriptor in fetchRequest, they must generate the same relative orderings. For example, the first sort descriptor in fetchRequest might specify the key for a persistent property; sectionNameKeyPath might specify a key for a transient property derived from the persistent property.
     * @param string|null $cacheName The name of the cache file the receiver should use. Pass null to prevent caching. Pre-computed section info is cached to a private directory under this name. If Core Data finds a cache stored with this name, it is checked to see if it matches the fetchRequest. If it does, the cache is loaded directly—this avoids the overhead of computing the section and index information. If the cached information doesn't match the request, the cache is deleted and recomputed when the fetch happens.
     */
    public function __construct(public readonly FetchRequest $fetchRequest, public readonly ManagedObjectContext $managedObjectContext, public readonly ?string $sectionNameKeyPath = null, public readonly ?string $cacheName = null)
    {
        NotificationCenter::default()->addObserverForName(ManagedObjectContext::didChangeObjectsNotification, $this->managedObjectContext, function (Notification $notification): void {
            $this->processManagedObjectContextChanges($notification);
        });
    }

    /**
     * @param ArrayClass<ResultType> $objects
     * @param string $keyPath
     * @return ArrayClass<FetchedResultsSectionInfo>
     */
    private function buildSections(ArrayClass $objects, string $keyPath): ArrayClass
    {
        return $objects->reduce(new Dictionary(),
            /**
             * @param Dictionary<FetchedResultsSectionInfo> $initialResult
             * @param ResultType $object
             * @return Dictionary<FetchedResultsSectionInfo>
             */
            function (Dictionary $initialResult, mixed $object) use ($keyPath): Dictionary {
                $sectionName = $object->valueForKeyPath($keyPath) ?? "";
                /** @var FetchedResultsSectionInfo $sectionInfo */
                $sectionInfo = $initialResult[$sectionName] ?? new FetchedResultsSectionInfo($sectionName, new ArrayClass());
                $sectionInfo->objects->append($object);
                $initialResult[$sectionName] = $sectionInfo;
                return $initialResult;
            })->values;
    }

    /**
     * Compares the name rather than the entity: on a request built from an entity name, reading
     * `entity` resolves it through the current operation queue's context and raises outside one.
     */
    private function isFetchedEntity(EntityDescription $entity): bool
    {
        if (!($entityName = $this->fetchRequest->entityName)) {
            return false;
        }
        if ($entity->name === $entityName) {
            return true;
        }
        return $this->fetchRequest->includesSubentities && $entity->isKindOf(EntityDescription::entity($entityName, $this->managedObjectContext));
    }

    /**
     * @throws Exception
     */
    private function processManagedObjectContextChanges(Notification $notification): void
    {
        /** @var Dictionary<Set<ResultType>> $userInfo */
        $userInfo = $notification->userInfo ?? new Dictionary();
        /** @var Set<ResultType> $inserted */
        $inserted = $userInfo[InsertedObjectsKey] ?? new Set();
        /** @var Set<ResultType> $updated */
        $updated = $userInfo[UpdatedObjectsKey] ?? new Set();
        /** @var Set<ResultType> $deleted */
        $deleted = $userInfo[DeletedObjectsKey] ?? new Set();
        /** @var ArrayClass<ResultType> $affected */
        $affected = new ArrayClass($inserted)->appendingContentsOf($updated)->appendingContentsOf($deleted)->filter(
        /**
         * @param ResultType $object
         * @return bool
         */
            fn(mixed $object): bool => $this->isFetchedEntity($object->entity));
        if ($affected->isEmpty) {
            return;
        }
        $oldFetched = $this->fetchedObjects;
        $newFetched = $oldFetched->filter(
        /**
         * @param ResultType $object
         * @return bool
         */
            fn(mixed $object): bool => !$deleted->containsElement($object))->appendingContentsOf($inserted->filter(
            /**
             * @param ResultType $object
             * @return bool
             */
                fn(mixed $object): bool => $this->isFetchedEntity($object->entity)));
        if ($sortDescriptors = $this->fetchRequest->sortDescriptors) {
            $newFetched = $newFetched->sorted($sortDescriptors);
        }
        $difference = $newFetched->difference($oldFetched)->inferringMoves();
        $this->fetchedObjects = $newFetched;
        if ($this->sectionNameKeyPath !== null) {
            $this->sections = $this->buildSections($newFetched, $this->sectionNameKeyPath);
        }
        $this->delegate?->controllerDidChangeContentWithDifference($this, $difference);
    }

    /**
     * Executes the controller's fetch request.
     *
     * After you execute this method, access the controller's fetched objects using the fetchedObjects property.
     * If you specify a value for the sectionNameKeyPath parameter when you initialize the fetched results' controller, the fetch request must include a sort descriptor for the corresponding key path; otherwise, the fetch fails.
     * @throws Exception
     */
    public function performFetch(): void
    {
        $sectionNameKeyPath = $this->sectionNameKeyPath ?? "";
        $this->fetchedObjects = $this->managedObjectContext->fetch($this->fetchRequest);
        if ($sectionNameKeyPath !== "") {
            $this->sections = $this->buildSections($this->fetchedObjects, $sectionNameKeyPath);
        } else {
            $this->sections = new ArrayClass([new FetchedResultsSectionInfo($sectionNameKeyPath, $this->fetchedObjects, $this->sectionIndexTitle($sectionNameKeyPath))]);
        }
    }

    /**
     * Deletes the cached section information with the given name.
     * @param string|null $name The name of the cache file to delete. If `$name` is null, deletes all cache files.
     */
    public static function deleteCache(?string $name): void
    {
    }

    /**
     * Returns the object at the given index path in the fetch results.
     * @param IndexPath $indexPath An index path in the fetch results. If indexPath does not describe a valid index path in the fetch results, an exception is raised.
     * @return ResultType The object at a given index path in the fetch results.
     */
    public function object(IndexPath $indexPath)
    {
        return $this->sections[$indexPath->section]->objects[$indexPath->row];
    }

    /**
     * Returns the index path of a given object.
     * @param ResultType $object An object in the receiver's fetch results.
     * @return IndexPath|null The index path of `$object` in the receiver's fetch results, or null if `$object` could not be found.
     */
    public function indexPath(mixed $object): ?IndexPath
    {
        foreach ($this->sections as $section => $e) {
            if (($row = $e->objects->indexOf($object)) !== null) {
                return new IndexPath([$section, $row]);
            }
        }
        return null;
    }

    /**
     * Returns the section number for a given section title and index in the section index.
     * @param string $title The title of a section
     * @param int $at The index of a section.
     * @return int The section number for the given section title and index in the section index
     */
    public function section(string $title, int $at): int
    {
        return $this->sections->offsetExists($at) && $this->sections[$at]->indexTitle === $title ? $at : $this->sections->firstIndex(fn(FetchedResultsSectionInfo $section): bool => $section->indexTitle === $title) ?? NotFound;
    }

    /**
     * Returns the corresponding section index entry for a given section name.
     *
     * The default implementation returns the capitalized first letter of the section name. You should override this method if you need a different way to convert from a section name to its name in the section index.
     * @param string $sectionName The name of a section.
     * @return string|null The section index entry corresponding to the section with name sectionName.
     */
    public function sectionIndexTitle(string $sectionName): ?string
    {
        return $sectionName === "" ? null : capitalize($sectionName);
    }
}
