<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Exception;
use InvalidArgumentException;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\OperationQueue;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\SortDescriptor;
use function Sabatier\Foundation\fatal_error;

/**
 * A description of search criteria used to retrieve data from a persistent store.
 * @template ResultType
 */
final class FetchRequest extends PersistentStoreRequest
{
    /** @var bool A Boolean value that indicates whether the fetch request includes subentities in the results. */
    public bool $includesSubentities = true;
    /** @var int The fetch limit of the fetch request. The fetch limit specifies the maximum number of objects that a request should return when executed. If you set a fetch limit, the framework makes the best effort to improve efficiency but does not guarantee it. For every object store except the SQL store, a fetch request executed with a fetch limit in effect simply performs an unlimited fetch and throws away the unasked for rows. */
    public int $fetchLimit = 0;
    /** @var int The fetch offset of the fetch request. This setting allows you to specify an offset at which rows will begin being returned. Effectively, the request skips the specified number of matching entries. For example, given a fetch that typically returns a, b, c, d, specifying an offset of 1 will return b, c, d, and an offset of 4 will return an empty array. Offsets are ignored in nested requests such as subqueries. This property can be used to restrict the working set of data. In combination with {@see fetchLimit}, you can create a subrange of an arbitrary result set. */
    public int $fetchOffset = 0;
    /** @var int The batch size of the objects specified in the fetch request. A batch size of 0 is treated as infinite, which disables the batch faulting behavior. If you set a nonzero batch size, the collection of objects returned when an instance of FetchRequest is executed is broken into batches. When the fetch is executed, the entire request is evaluated, and the identities of all matching objects recorded, but only data for objects up to the batchSize will be fetched from the persistent store at a time. The array returned from executing the request is a proxy object that transparently faults batches on demand. (In database terms, this is an in-memory cursor.) You can use this feature to restrict the working set of data in your application. In combination with {@see fetchLimit}, you can create a subrange of an arbitrary result set. */
    public int $fetchBatchSize = 0;
    /** @var ArrayClass<SortDescriptor>|null The sort descriptors specify how the objects returned when the FetchRequest is issued should be ordered, for example, by last name and then by first name. The sort descriptors are applied in the order in which they appear in the sortDescriptors array (serially in lowest array-index-first order). A value of null is treated as no sort descriptors. */
    public ?ArrayClass $sortDescriptors = null;
    /** @var ArrayClass<string>|null The relationship key paths to prefetch along with the entity for the request. */
    public ?ArrayClass $relationshipKeyPathsForPrefetching = null;
    /** @var bool A Boolean value that indicates whether, when the fetch is executed, it matches against currently unsaved changes in the managed object context. This value is true if when the fetch is executed, the fetch will match against currently unsaved changes in the managed object context; otherwise the value is false. If the value is false, the fetch request doesn't check unsaved changes and only returns objects that matched the predicate in the persistent store. */
    public bool $includesPendingChanges = false;
    /** @var bool A Boolean value that indicates whether the fetch request returns only distinct values for the fields specified by {@see $propertiesToFetch}. This value is used only if a value has been set for {@see $propertiesToFetch}. This value is true if when the fetch is executed, it returns only distinct values for the fields specified by {@see $propertiesToFetch}; otherwise, the value is false. The default value is false. */
    public bool $returnsDistinctResults = false;
    /** @var bool A Boolean value that indicates whether, when the fetch is executed, property data is obtained from the persistent store. This value is true if when the fetch is executed, property data is obtained from the persistent store; otherwise it is false. The default value is true. You can set includesPropertyValues to {@see false} to avoid creating objects to represent property values and thereby reduce memory overhead. You typically should only do so, however, if you are sure that you will not need the actual property data, or you already have the information in the row cache. Otherwise, you will incur multiple trips to the database. During a normal fetch (includesPropertyValues is true), Core Data fetches the object ID and property data for the matching records, fills the row cache with the information, and returns managed objects as faults (see returnsObjectsAsFaults). Although these faults are managed objects, all of their property data still resides in the row cache until the fault is fired. When the fault is fired, Core Data retrieves the data from the row cache—there is no need to go back to the database. If includesPropertyValues is false, then Core Data fetches only the object ID information for the matching records—it does not populate the row cache. Core Data still returns managed objects because it only needs managed object IDs to create faults. However, if you subsequently fire the fault, Core Data looks in the (empty) row cache, doesn't find any data, and then goes back to the store a second time for the data. If includesPropertyValues is true and resultType is set to managedObjectIDResultType, the properties are fetched even though they are not being presented to the application and can result in a significant performance penalty.
     */
    public bool $includesPropertyValues = true;
    /** @var bool A Boolean value that indicates whether the property values of fetched objects will be updated with the current values in the persistent store. This value is true if the property values of fetched objects will be updated with the current values in the persistent store; otherwise, it is false. By default, when you fetch objects, they maintain their current property values, even if the values in the persistent store have changed. Invoking this method with the parameter true means that when the fetch is executed, the property values of fetched objects are updated with the current values in the persistent store. This is a more convenient way to ensure that managed object property values are consistent with the store than by using {@see ManagedObjectContext::refresh()} for multiple objects in turn. */
    public bool $shouldRefreshRefetchedObjects = false;
    /** @var bool A Boolean value that indicates whether the objects resulting from a fetch request are faults. This value is true if the objects resulting from a fetch using the FetchRequest are faults; otherwise, it is false. The default value is true. This setting is not used if the result type (see {@see resultType}) is {@see FetchRequestResultType::objectID}, as object IDs do not have property values. You can set {@see returnsObjectsAsFaults} to {@see false} to gain a performance benefit if you know you will need to access the property values from the returned objects. When you execute a fetch, by default returnsObjectsAsFaults is true; Core Data fetches the object data for the matching records, fills the row cache with the information, and returns ManagedObject as faults. These faults are managed objects, but all of their property data resides in the row cache until the fault is fired. When the fault is fired, Core Data retrieves the data from the row cache. Although the overhead for this operation is small, for large datasets it may not be trivial. If you need to access the property values from the returned objects (for example, if you iterate over all the objects to calculate the average value of a particular attribute), then it is more efficient to set {@see returnsObjectsAsFaults} to {@see false} to avoid the additional overhead.
     */
    public bool $returnsObjectsAsFaults = false;
    /** @var Predicate|null The predicate used to filter rows being returned by a query containing a GROUP BY directive. If a havingPredicate value is supplied, the predicate will be run after. Specifying a havingPredicate requires that {@see propertiesToGroupBy} also be specified. */
    public ?Predicate $havingPredicate = null;
    /** @var FetchRequestResultType The result type of the fetch request. If you set the value to {@see FetchRequestResultType::objectID}, and do not include property values in the request, sort orderings are demoted to “best efforts” hints. {@see includesPendingChanges} discusses with whether pending changes are taken into account when the resultType is set to {@see FetchRequestResultType::object}. {@see includesPropertyValues} discusses whether property values are included or not by default when the resultType is set to {@see FetchRequestResultType::object}. */
    public FetchRequestResultType $resultType = FetchRequestResultType::managedObjectResultType;
    /** @var EntityDescription The entity specified for the fetch request. When a FetchRequest instance is created without {@see entityName}, it is expected that the entity property will be set. If this property is not set, the fetch request fails upon execution. */
    public EntityDescription $entity {
        get => $this->entity ??= EntityDescription::entity($this->entityName ?? fatal_error("Invalid fetch request: expecting an entity or an entity name"), $this->context());
    }
    /** @var Predicate|null The predicate of the fetch request. The predicate instance constrains the selection of objects the FetchRequest instance is to fetch. If the predicate is empty, for example, if it is an AND predicate whose array of elements contains no predicates, the request has its predicate set to null. */
    public ?Predicate $predicate = null;
    /** @var ArrayClass<string|PropertyDescription>|null A collection of either property descriptions or string property names that specify which properties should be returned by the fetch. You must set the entity for the fetch request before setting this value; otherwise, FetchRequest throws an {@see InvalidArgumentException} exception. Property descriptions can either be instances of {@see PropertyDescription} or string. The property descriptions may represent attributes, to-one relationships, or expressions. The name of an attribute or relationship description must match the name of a description on the fetch request's entity. This property can be set with {@see FetchRequestResultType::object} and thereby implement a partial faulting (whereby only some properties are populated) of the returned objects, as well as the {@see FetchRequestResultType::dictionary} to define what properties are included in the resulting {@see Dictionary}. */
    public ?ArrayClass $propertiesToFetch = null;
    /** @var ArrayClass<string|PropertyDescription>|null An array of objects that indicates how data should be grouped before a select statement is run in an SQL database. An array of {@see PropertyDescription} or {@see ExpressionDescription} objects or key-path strings that indicate how data should be grouped before a select statement is run in an SQL database. If you use this setting, you must set the resultType to {@see FetchRequestResultType::dictionary}, and the SELECT values must be literals, aggregates, or columns specified in propertiesToGroupBy. Aggregates will operate on the groups specified in propertiesToGroupBy rather than the whole table. If you set propertiesToGroupBy, you can also set a predicate to filter rows that are returned by propertiesToGroupBy. */
    public ?ArrayClass $propertiesToGroupBy = null;
    /** @var Dictionary<mixed> Declarative serialization shape used to control which properties are fetched from the persistent store.
     * This nested dictionary determines the exact structure to retrieve, and is later applied to the fetched objects to configure their serializationRule and serializationKeys, ensuring their serialized representation matches the requested shape. */
    public Dictionary $serialization {
        get => $this->serialization ??= ($this->propertiesToFetch?->compactMap(fn(PropertyDescription|string $property): ?PropertyDescription => $property instanceof PropertyDescription ? $property : $this->entity->propertiesByName[$property]) ?? $this->entity->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$attribute->isTransient && (!$attribute instanceof DerivedAttributeDescription || ($expression = $attribute->derivationExpression) && !new PredicatePersistenceChecker($expression)->isRuntimeOnly))->values)->reduce(new Dictionary(), function (Dictionary $result, PropertyDescription $propertyDescription): Dictionary {
            if ($propertyDescription instanceof AttributeDescription) {
                $result[$propertyDescription->name] = $propertyDescription->type;
            } elseif ($propertyDescription instanceof RelationshipDescription) {
                $destinationEntity = $propertyDescription->destinationEntity;
                $result[$propertyDescription->name] = $destinationEntity->attributesByName->reduce(new Dictionary(), function (Dictionary $result, AttributeDescription $attribute): Dictionary {
                    if (!$attribute->isTransient && !$attribute instanceof DerivedAttributeDescription) {
                        $result[$attribute->name] = $attribute->type;
                    }
                    return $result;
                });
            }
            return $result;
        });
    }
    /** @var string|null The name of the entity to fetch. */
    public ?string $entityName {
        get => $this->entityName ??= $this->entity->name;
    }

    /**
     * Initializes a fetch request configured with a given entity name.
     * This method provides a convenient way to configure the entity for a fetch request without having to retrieve an {@see EntityDescription} object. When the fetch is executed, the request uses the managed object context to find the entity with the given name. The model associated with the context's persistent store coordinator must contain an entity named entityName.
     * @param string|null $entityName The name of the entity to fetch.
     */
    public function __construct(?string $entityName = null)
    {
        parent::__construct();
        if ($entityName) {
            $this->entityName = $entityName;
        }
    }

    /**
     * Executes the fetch request against the managed object context that is associated with the current queue.
     *
     * Calling {@see execute()} on an FetchRequest will cause the FetchRequest to run against the managed object context ({@see ManagedObjectContext}) that is associated with the queue on which the method is called.
     * @return ArrayClass<ResultType>
     * @throws Exception
     */
    public function execute(): ArrayClass
    {
        return $this->context()->fetch($this);
    }

    private function context(): ManagedObjectContext
    {
        if (!($queue = OperationQueue::current())) {
            fatal_error("Current operation queue not found");
        }
        if (!($context = $queue->associatedValues["managedObjectContext"])) {
            fatal_error("Unable to find the managed object context associated with the current operation queue");
        }
        return $context;
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        return $this->dictionaryWithValues(new ArrayClass(["includesSubentities", "fetchLimit", "fetchOffset", "fetchBatchSize", "sortDescriptors", "includesPendingChanges", "returnsDistinctResults", "includesPropertyValues", "shouldRefreshRefetchedObjects", "returnsObjectsAsFaults", "havingPredicate", "resultType", "entity", "predicate", "propertiesToFetch", "propertiesToGroupBy", "entityName"]));
    }
}
