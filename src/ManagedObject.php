<?php

namespace Sabatier\CoreData;

use BackedEnum;
use Exception;
use JetBrains\PhpStorm\ExpectedValues;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonResult;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\KeyValueChange;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\SensitiveValue;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\Value;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\localized_string;
use function Sabatier\Foundation\typeof;
use const Sabatier\Foundation\LocalizedDescriptionKey;
use const Sabatier\Foundation\LocalizedFailureReasonErrorKey;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/**
 * A base class that implements the behavior required of a Core Data model object.
 */
class ManagedObject extends ObjectClass implements FetchRequestResult
{
    use FaultingSetMutationMethods;

    /** @var bool A Boolean value that indicates whether to mark instances of the class as having changes when an unmodeled rela$relationship changes. False if instances of the class should be marked as having changes if an unmodeled rela$relationship is changed, otherwise true. The default value is true. */
    public static bool $contextShouldIgnoreUnmodeledPropertyChanges = true;
    /** @var EntityDescription The entity description of the managed object. */
    public readonly EntityDescription $entity;
    /** @var ManagedObjectID The object ID of the managed object. If the receiver is a fault, accessing this rela$relationship does not cause it to fire. If the receiver has not yet been saved, the object ID is a temporary value that will change when the object is saved. */
    public ManagedObjectID $objectID {
        get => $this->objectID ??= new ManagedObjectID($this->entity, new UUID()->uuidString);
    }
    /** @var int Object version used for optimistic locking. The default value is 1. */
    public int $version = 1;
    /** @var bool A Boolean value that indicates whether the managed object has been inserted in a managed object context. */
    public bool $isInserted {
        /**
         * @throws Exception
         */
        get {
            if (isset($this->isInserted)) {
                return $this->isInserted;
            }
            if ($this->isStable) {
                return $this->isInserted = true;
            }
            if ($this->objectID->isTemporaryID) {
                return $this->isInserted = false;
            }
            if (!($persistentStore = $this->objectID->persistentStore)) {
                return $this->isInserted = false;
            }
            $debugDefault = SQLCore::$debugLevel;
            SQLCore::$debugLevel = SQLDebugLevel::none;
            /** @var FetchRequest<Number> $fetchRequest */
            $fetchRequest = $this::fetchRequest();
            $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(ManagedObjectObjectIDKey), Expression::expressionForConstantValue($this->objectID->referenceObject));
            $fetchRequest->affectedStores = new ArrayClass([$persistentStore]);
            $this->isInserted = (bool)$this->managedObjectContext->count($fetchRequest);
            SQLCore::$debugLevel = $debugDefault;
            return $this->isInserted;
        }
    }
    /** @var bool A Boolean value that indicates whether the managed object has unsaved changes. */
    public bool $isUpdated {
        get => $this->isUpdated ??= !$this->changedValuesForCurrentEvent->isEmpty && $this->isInserted;
    }
    /** @var bool A Boolean value that indicates whether the managed object will be deleted during the next save. */
    public bool $isDeleted {
        get => $this->isDeleted ??= $this->managedObjectContext->deletedObjects->containsElement($this);
    }
    /** @var bool A Boolean value that indicates whether the managed object has been inserted, has been deleted, or has unsaved changes, true if the receiver has been inserted, has been deleted, or has unsaved changes, otherwise false. The result is the equivalent of OR-ing the values of isInserted, isDeleted, and isUpdated. */
    public bool $hasChanges {
        get => $this->isInserted || $this->isUpdated || $this->isDeleted;
    }
    public readonly ManagedObjectContext $managedObjectContext;
    /** @var Dictionary<mixed> */
    private Dictionary $changedValues {
        get => $this->changedValues ??= new Dictionary();
    }
    /** @var Dictionary<mixed> */
    private Dictionary $changedValuesForCurrentEvent {
        get => $this->changedValuesForCurrentEvent ??= new Dictionary();
    }
    /** @var bool A Boolean value that indicates whether the managed object is a fault. Knowing whether an object is a fault is useful in many situations when computations are optional. It can also be used to avoid growing the object graph unnecessarily (which may improve performance as it can avoid time-consuming fetches from data stores). If this rela$relationship is false, then the receiver's data must be in memory. However, if this rela$relationship is true, it does not mean that the data is not in memory. The data may be in memory, or it may not, depending on many factors influencing caching. If the receiver is a fault, accessing this rela$relationship does not cause it to fire. */
    public bool $isFault = true;
    /** @var int The faulting state of the managed object. 0 if the object is fully initialized as a managed object and not transitioning to or from another state, otherwise some other value. */
    public int $faultingState = ManagedObjectFaultingStateUnstable;
    /** @var ArrayClass<string> */
    private ArrayClass $serializationKeys {
        get => ManagedObjectSerializationPreparer::shared()->serializationKeysForObject($this) ?? $this->entity->defaultSerializationKeys;
    }
    /** @internal */
    public FaultHandler $faultHandler {
        get => $this->faultHandler ??= ($this->managedObjectContext->persistentStoreCoordinator?->persistentStoreForObject($this) ?? fatal_error("Persistent store coordinator cannot be null"))->faultHandler;
    }
    /**
     * @var Dictionary<PropertyDescription>
     * @internal
     */
    private(set) Dictionary $allProperties {
        get => $this->allProperties ??= $this->entity->propertiesByName;
    }
    /**
     * @var Dictionary<PropertyDescription>
     * @internal
     */
    public Dictionary $modeledProperties {
        get => $this->allProperties;
    }
    /**
     * @var Dictionary<PropertyDescription>
     * @internal
     */
    private(set) Dictionary $persistentProperties {
        get => $this->persistentProperties ??= $this->modeledProperties->filter(fn(PropertyDescription $property): bool => !$property->isTransient && ($property instanceof DerivedAttributeDescription ? !$property->isRuntimeOnly : !$property instanceof FetchedPropertyDescription));
    }
    /**
     * @var Dictionary<PropertyDescription>
     * @internal
     */
    private(set) Dictionary $transientProperties {
        get => $this->transientProperties ??= $this->modeledProperties->filter(fn(PropertyDescription $property): bool => $property->isTransient);
    }
    /**
     * @var Dictionary<AttributeDescription>
     * @internal
     */
    public Dictionary $modeledAttributes {
        get => $this->modeledAttributes ??= $this->modeledProperties->filter(fn(PropertyDescription $property): bool => $property instanceof AttributeDescription);
    }
    /**
     * @var Dictionary<RelationshipDescription>
     * @internal
     */
    public Dictionary $modeledRelationships {
        get => $this->modeledRelationships ??= $this->modeledProperties->filter(fn(PropertyDescription $property): bool => $property instanceof RelationshipDescription);
    }
    /**
     * @var Dictionary<FetchedPropertyDescription>
     * @internal
     */
    public Dictionary $modeledFetchedProperties {
        get => $this->modeledFetchedProperties ??= $this->modeledProperties->filter(fn(PropertyDescription $property): bool => $property instanceof FetchedPropertyDescription);
    }
    /** @var array<string, bool> */
    private array $resolvingKeys = [];
    /** @internal */
    public bool $isSuppressingKVO = false;
    /** @internal */
    public bool $isSuppressingChangeNotifications = false;
    /** @internal */
    public bool $isAwakeFromFetch = false;
    /** @internal */
    private(set) string $entityName {
        get => $this->entityName ??= $this->entity->name;
    }
    #[Override]
    final public string $description {
        get => sprintf("<%s %s> (entity: %s; id: %s %s; data: %s)", $this->class, $this->hash, $this->entity->name, $this->objectID->hash, $this->objectID->description, $this->isFault ? "<fault>" : $this->dictionaryWithValues($this->serializationKeys->filter(fn(string $key): bool => !$this->isPropertyForKeyFault($key)))->description);
    }
    #[Override]
    public string $debugDescription {
        get => sprintf("<%s %s> %s", $this->class, $this->hash, $this->objectID->referenceObject);
    }
    #[Override]
    public string $canonicalDescription {
        get => sprintf("<%s:%s>", $this->entity->name, $this->objectID->referenceObject);
    }
    /**
     * @var Dictionary<mixed>|null
     * @internal
     */
    public ?Dictionary $originalSnapshot = null;
    /**
     * @var Dictionary<mixed>|null
     * @internal
     */
    public ?Dictionary $lastSnapshot = null;
    /** @var Dictionary<mixed> */
    private Dictionary $committedValues {
        /**
         * @throws Exception
         */
        get => $this->committedValues ??= $this->originalSnapshot?->reduce(new Dictionary(),
            /**
             * @param Dictionary<mixed> $initialResult
             * @param mixed $value
             * @param string $key
             * @return Dictionary<mixed>
             * @throws Exception
             */
            function (Dictionary $initialResult, mixed $value, string $key): Dictionary {
                $property = $this->modeledProperties[$key];
                if ($property instanceof AttributeDescription) {
                    if ($this->isSubclass(ManagedObject::class)) {
                        $this->dispatchValidationHook($key, $value);
                    }
                } elseif ($property instanceof RelationshipDescription) {
                    $context = $this->managedObjectContext;
                    $value = $context->newValueForRelationship($property, $this->objectID);
                    if ($value instanceof ManagedObjectID) {
                        $value = $context->object($value);
                    }
                }
                $initialResult[$key] = $value;
                return $initialResult;
            }) ?? new Dictionary();
    }
    private SnapshotValueMapper $snapshotValueMapper {
        get => $this->snapshotValueMapper ??= new SnapshotValueMapper($this->managedObjectContext->persistentStoreCoordinator?->persistentStoreForObject($this) ?? fatal_error("Persistent store coordinator cannot be null"), $this->managedObjectContext);
    }
    /** @internal */
    public bool $isStable {
        get => $this->faultingState === ManagedObjectFaultingStateStable && !$this->isFault;
    }

    /**
     * Initializes a managed object from an entity description and inserts it into the specified managed object context.
     * @param ManagedObjectContext $managedObjectContext The context into which the new instance is inserted.
     * @param EntityDescription|null $entity The entity of which to create an instance.
     * The model associated with context's persistent store coordinator must contain $entity.
     * If the receiver is a fault, accessing this rela$relationship does not cause it to fire.
     */
    public function __construct(ManagedObjectContext $managedObjectContext, ?EntityDescription $entity = null)
    {
        if ($this->isSubclass(ManagedObject::class)) {
            $entity ??= static::entity();
        }
        $this->entity = $entity ?? fatal_error("Invalid argument: entity cannot be null");
        $this->managedObjectContext = $managedObjectContext;
        $this->managedObjectContext->insert($this);
    }

    public function __get(string $name)
    {
        return $this->valueForKey($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setValueForKey($value, $name);
    }

    /**
     * Returns the entity description associated with this subclass.
     *
     * This method is only legal to call on subclasses of ManagedObject that represent a single entity in the model.
     * @return EntityDescription
     */
    public static function entity(): EntityDescription
    {
        return static::staticAssociatedValueForKey(__FUNCTION__) ?? fatal_error(sprintf("Class %s does not have an entity description. You must either implement the %s method to return an entity description, or ensure that the class name matches an entity in the model.", static::class, __FUNCTION__));
    }

    /**
     * Returns a Boolean value that indicates whether the relationship for a given key is a fault.
     *
     * If the specified relationship is a fault, calling this method does not result in the fault firing.
     * @param string $key The name of one of the receiver's relationships.
     * @return bool true if the relationship for the key is a fault, otherwise false.
     */
    public function hasFaultForRelationshipNamed(string $key): bool
    {
        $this->entity->relationshipsByName->offsetExists($key) ?: $this->valueForUndefinedKey($key);
        return $this->isPropertyForKeyFault($key);
    }

    /** @internal */
    public function isPropertyForKeyFault(string $key): bool
    {
        $value = $this->primitiveValueForKey($key);
        $property = $this->entity->propertiesByName[$key];
        if (($property instanceof FetchedPropertyDescription && $value instanceof FaultingArray) || ($property instanceof RelationshipDescription && $value instanceof FaultingSet)) {
            return $value->isFault;
        }
        return $value === null;
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object when fulfilling it from a fault.
     *
     * You typically use this method to compute derived values or to recreate transient relationships from the receiver's persistent properties. The managed object context's change processing is explicitly disabled around this method so that you can use public setters to establish transient values and other caches without dirtying the object or its context. Because of this, however, you should not modify relationships in this method as the inverse will not be set. Subclasses must invoke super's implementation before performing their own initialization.
     */
    public function awakeFromFetch(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object when initially creating it.
     *
     * You typically use this method to initialize special default rela$relationship values. This method is invoked only once in the object's lifetime. If you want to set attribute values in an implementation of this method, you should typically use primitive accessor methods (either {@see setPrimitiveValueForKey()} or better the appropriate custom primitive accessors). This ensures that the new values are treated as baseline values rather than being recorded as undoable changes for the properties in question. Subclasses must invoke super's implementation before performing their own initialization.
     */
    public function awakeFromInsert(): void
    {
        $this->hydrateProperties();
    }

    private function hydrateProperties(): void
    {
        $isManagedObjectSubclass = $this->isSubclass(ManagedObject::class);
        $this->hydrateRelationships($isManagedObjectSubclass);
        $this->hydrateAttributes($isManagedObjectSubclass);
    }

    private function hydrateRelationships(bool $enableMutators): void
    {
        foreach ($this->modeledRelationships as $relationship) {
            $this->setupRelationshipDynamicMethods($relationship, $enableMutators);
        }
    }

    private function setupRelationshipDynamicMethods(RelationshipDescription $relationship, bool $enableMutators): void
    {
        if ($relationship->isToMany && $enableMutators) {
            $this->createMutationMethods($relationship->name);
        }
    }

    private function hydrateAttributes(bool $shouldValidate): void
    {
        foreach ($this->modeledAttributes as $attribute) {
            $key = $attribute->name;
            $value = $this->primitiveValueForKey($key);
            $value = $this->resolveInitialAttributeValue($attribute, $value, $shouldValidate);
            $this->setPrimitiveValueForKey($value, $key);
        }
    }

    private function resolveInitialAttributeValue(AttributeDescription $attribute, mixed $value, bool $shouldValidate): mixed
    {
        $value ??= $attribute->defaultValue;
        if (!$attribute->isOptional) {
            $value = $this->applyTypeCoercionFallback($attribute, $value);
        }
        if ($shouldValidate) {
            $this->dispatchValidationHook($attribute->name, $value);
        }
        return $value;
    }

    private function applyTypeCoercionFallback(AttributeDescription $attribute, mixed $value): mixed
    {
        return match ($attribute->type) {
            AttributeType::undefined, AttributeType::binaryData, AttributeType::objectID, AttributeType::compositeAttributeType => null,
            default => self::coercedValue($value, $attribute->type, $attribute->attributeValueClassName, $attribute->valueTransformerName, $attribute->isOptional),
        };
    }

    private function dispatchValidationHook(string $key, mixed &$value): void
    {
        $method = "validate" . ucfirst($key);
        if (method_exists($this, $method)) {
            $this->$method($value);
        }
    }

    /**
     * @param Dictionary<mixed> $snapshot
     */
    private function genericUpdateFromSnapshot(Dictionary $snapshot): void
    {
        $this->setValuesForKeys($this->snapshotValueMapper->mapSnapshot($this, $snapshot));
    }

    /**
     * @param Dictionary<mixed> $snapshot
     * @param bool $includingTransients
     * @internal
     */
    public function updateFromUndoSnapshot(Dictionary $snapshot, bool $includingTransients): void
    {
        if (!$includingTransients) {
            $snapshot = $snapshot->filter(fn(mixed $value, string $key): bool => !$this->transientProperties->offsetExists($key));
        }
        $this->genericUpdateFromSnapshot($snapshot);
        $this->lastSnapshot = $snapshot;
    }

    /**
     * @param Dictionary<mixed> $snapshot
     * @internal
     */
    public function updateFromRefreshSnapshot(Dictionary $snapshot): void
    {
        $this->genericUpdateFromSnapshot($snapshot);
        $this->lastSnapshot = $snapshot;

    }

    /**
     * @param Dictionary<mixed> $snapshot
     * @internal
     */
    public function updateFromSnapshot(Dictionary $snapshot): void
    {
        $this->genericUpdateFromSnapshot($snapshot);
        $this->originalSnapshot ??= $snapshot;
        $this->lastSnapshot = $snapshot;
    }

    /**
     * @param Dictionary<mixed> $snapshot
     * @internal
     */
    public function materializeFaultsFromSnapshot(Dictionary $snapshot): void
    {
        $this->refaultEmptyToOneRelationships();
        $this->genericUpdateFromSnapshot($snapshot->filter(fn(mixed $value, string $key): bool => $this->isPropertyForKeyFault($key)));
    }

    private function refaultEmptyToOneRelationships(): void
    {
        $context = $this->managedObjectContext;
        if ($context->updatedObjects->containsElement($this) || $context->deletedObjects->containsElement($this)) {
            return;
        }
        foreach ($this->modeledRelationships as $relationship) {
            if ($relationship->isToMany || $this->changedValuesForCurrentEvent->offsetExists($relationship->name)) {
                continue;
            }
            if ($this->primitiveValueForKey($relationship->name) instanceof Nil) {
                $this->setPrimitiveValueForKey(null, $relationship->name);
            }
        }
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object when fulfilling it from a snapshot.
     *
     * You typically use this method to compute derived values or to recreate transient relationships from the receiver's persistent properties. If you want to set attribute values and need to avoid emitting key-value observation change notifications, you should use primitive accessor methods (either {@see setPrimitiveValue()} or better the appropriate custom primitive accessors). This ensures that the new values are treated as baseline values rather than being recorded as undoable changes for the properties in question. Subclasses must invoke super's implementation before performing their own initialization.
     * @param int $flags A bit mask of {@see SnapshotEventType} constants to denote the event or events that led to the method being invoked.
     * For possible values, see {@see SnapshotEventType}.
     */
    public function awakeFromSnapshotEvents(#[ExpectedValues(flagsFromClass: SnapshotEventType::class)] int $flags): void
    {
    }

    /**
     * Returns a dictionary containing the keys and new values of persistent properties with changes since the last fetching or saving of the managed object.
     *
     * This method only reports changes to properties that are persistent properties of the receiver, not changes to transient properties or custom instance variables.
     * @return Dictionary<mixed> A dictionary with keys that are the names of persistent properties with changes since last fetching or saving the receiver, and with the new values for those properties.
     */
    public function changedValues(): Dictionary
    {
        return $this->changedValues->filter(fn(mixed $value, string $key): bool => $this->persistentProperties->offsetExists($key));
    }

    /**
     * Returns a dictionary containing the keys and new values of persistent properties with changes since the last fetching or saving of the managed object.
     *
     * This method only reports changes to properties that are persistent properties of the receiver, not changes to transient properties or custom instance variables.
     * @return Dictionary<mixed> A dictionary with keys that are the names of persistent properties with changes since the last posting of {@see ManagedObjectContextObjectsDidChange}, and with the new values for those properties.
     */
    public function changedValuesForCurrentEvent(): Dictionary
    {
        return $this->changedValuesForCurrentEvent;
    }

    /**
     * Returns a dictionary of the most recent fetched or saved values for the properties of the specified keys.
     *
     * This method only reports values of properties that are defined as persistent properties of the receiver, not values of transient properties or of custom instance variables.
     * You can invoke this method with the $keys value of null to retrieve committed values for all the receiver's properties, as illustrated by the following example.
     * <code>
     * $allCommittedValues = $managedObject->committedValuesForKeys(null);
     * </code>
     * It is more efficient to use null than to pass an array of all the rela$relationship keys.
     * @param ArrayClass<string>|null $keys An array containing names of properties, or null.
     * @return Dictionary<mixed> A dictionary containing the last fetched or saved values of the receiver for the properties specified by keys.
     */
    public function committedValues(?ArrayClass $keys): Dictionary
    {
        return $keys === null ? $this->committedValues : $this->committedValues->filter(fn(mixed $value, string $key): bool => $keys->containsElement($key));
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object before deleting it.
     *
     * You can implement this method to perform any operations required before the object is deleted, such as custom propagation before relationships are torn down, or reconfiguration of objects using key-value observing.
     */
    public function prepareForDeletion(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object before saving it.
     *
     * This method can have “side effects” on persistent values. You can use it to, for example, compute persistent values from other transient or scratchpad values.
     *
     * If you want to update a persistent rela$relationship value, you should typically test for equality of any new value with the existing value before making a change. If you change rela$relationship values using standard accessor methods, Core Data will observe the resultant change notification and so invoke willSave again before saving the object’s managed object context. If you continue to modify a value in willSave, willSave will continue to be called until your program crashes.
     *
     * For example, if you set a last-modified timestamp, you should check whether either you previously set it in the same save operation, or that the existing timestamp is not less than a small delta from the current time. Typically, it’s better to calculate the timestamp once for all the objects being saved (for example, in response to an {@see ManagedObjectContextWillSave}).
     *
     * If you change rela$relationship values using primitive accessors, you avoid the possibility of infinite recursion, but Core Data will not notice the change you make.
     *
     * The sense of “save” in the method name is that of a database commit statement and so applies to deletions as well as to updates to objects. For subclasses, this method is therefore an appropriate locus for code to be executed when an object is deleted as well as “saved to disk.” You can find out if an object is marked for deletion with {@see $isDeleted}.
     */
    public function willSave(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object after the managed object's context completes a save operation.
     *
     * You can use this method to notify other objects after a save and to compute transient values from persistent values.
     *
     * This method can have “side effects” on the persistent values, however, any changes you make using standard accessor methods will by default dirty the managed object context and leave your context with unsaved changes. Moreover, if the object’s context has an undo manager, such changes will add an undo operation. For document-based applications, changes made in didSave will therefore come into the next undo grouping, which can lead to “empty” undo operations from the user's perspective. You may want to disable undo registration to avoid this issue.
     *
     * The sense of “save” in the method name is that of a database commit statement and so applies to deletions as well as to updates to objects. For subclasses, this method is therefore an appropriate locus for code to be executed when an object is deleted as well as “saved to disk.” You can find out if an object is marked for deletion with {@see $isDeleted}.
     *
     * You cannot attempt to resurrect a deleted object in didSave.
     */
    public function didSave(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object before converting it to a fault.
     *
     * This method is the companion of the {@see didTurnIntoFault()} method. You can use it to (re)set the state which requires access to rela$relationship values (for example, observers across key paths).
     * The default implementation does nothing.
     */
    public function willTurnIntoFault(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object after converting it to a fault.
     *
     * You use this method to clear out custom data caches transient values declared as entity properties are typically already cleared out by the time this method is invoked (see, for example, {@see ManagedObjectContext::refresh()}).
     */
    public function didTurnIntoFault(): void
    {
    }

    /**
     * Returns an initialized fetch request with the entity this subclass represents.
     *
     * This method is only legal to call on subclasses of ManagedObject that represent a single entity in the model.
     * @return FetchRequest<static>
     */
    public static function fetchRequest(): FetchRequest
    {
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = self::entity();
        return $fetchRequest;
    }

    /**
     * @param string $key
     * @return Set<ManagedObject>
     */
    #[Override]
    public function mutableSetValueForKey(string $key): Set
    {
        if (!($relationship = $this->entity->relationshipsByName[$key])) {
            return $this->valueForUndefinedKey($key);
        }
        $relationship->isToMany ?: fatal_error("$this->debugDescription does not contains a to many relationship named \"$key\"");
        /** @var Set<ManagedObject>|null $mutableSet */
        $mutableSet = $this->primitiveValueForKey($key);
        if (!$mutableSet instanceof FaultingSet) {
            $faultingSet = new FaultingSet($this, $relationship);
            if ($mutableSet instanceof Set) {
                $faultingSet->formUnion($mutableSet);
            }
            $this->setPrimitiveValueForKey($faultingSet, $key);
            $mutableSet = $faultingSet;
        }
        return $mutableSet;
    }

    /**
     * @param string $key
     * @return ArrayClass<ManagedObject>
     */
    #[Override]
    public function mutableArrayValueForKey(string $key): ArrayClass
    {
        if (!($fetchedProperty = $this->modeledFetchedProperties[$key])) {
            return $this->valueForUndefinedKey($key);
        }
        /** @var ArrayClass<ManagedObject>|null $mutableArray */
        $mutableArray = $this->primitiveValueForKey($key);
        if (!$mutableArray instanceof FaultingArray) {
            $faultingArray = new FaultingArray($this, $fetchedProperty);
            if ($mutableArray instanceof ArrayClass) {
                $faultingArray->appendContentsOf($mutableArray);
            }
            $this->setPrimitiveValueForKey($faultingArray, $key);
            $mutableArray = $faultingArray;
        }
        return $mutableArray;
    }

    /**
     * Returns the value for the specified rela$relationship from the managed object's private internal storage.
     *
     * This method does not invoke the access notification methods ({@see willAccessValueForKey()} and {@see didAccessValueForKey()}).
     * This method is used primarily by subclasses that implement custom accessor methods that need direct access to the receiver's private storage.
     * @param string $key The name of one of the receiver's properties.
     * @return mixed The value of the rela$relationship specified by $key. Returns null if no value has been set.
     */
    final public function primitiveValueForKey(string $key): mixed
    {
        return $this->changedValues[$key];
    }

    /**
     * Sets the value of a given rela$relationship in the managed object's private internal storage.
     *
     * Sets in the receiver's private internal storage the value of the rela$relationship specified by $key to value.
     * If $key identifies a to-one relationship, relates the object specified by value to the receiver, unrelating the previously related object if there was one. Given a collection object and a key that identifies a to-many relationship, relates the objects contained in the collection to the receiver, unrelating previously related objects if there were any.
     * This method does not invoke the change notification methods ({@see willChangeValueForKey()} and {@see didChangeValueForKey()}).
     * It is typically used by subclasses that implement custom accessor methods that need direct access to the receiver's private internal storage. It is also used by the Core Data framework to initialize the receiver with values from a persistent store or to restore a value from a snapshot.
     * @param mixed|null $value The new value for the rela$relationship specified by $key.
     * @param string $key The name of one of the receiver's properties.
     */
    final public function setPrimitiveValueForKey(mixed $value, string $key): void
    {
        $this->changedValues[$key] = $value;
    }

    /**
     * Returns the value for the rela$relationship specified by $key.
     *
     * If $key is not a rela$relationship defined by the model, the method raises an exception.
     * This method is overridden by ManagedObject to access the managed object's generic dictionary storage unless the receiver's class explicitly provides key-value coding compliant accessor methods for $key.
     * @param string $key The name of one of the receiver's properties.
     * @return mixed The value of the rela$relationship specified by $key.
     * @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection
     */
    #[Override]
    final public function valueForKey(string $key): mixed
    {
        $key ?: $this->valueForUndefinedKey($key);
        $flag = $this->persistentProperties->offsetExists($key) && $this->isFault && !$this->isSuppressingKVO && $this->isInserted;
        $context = $this->managedObjectContext;
        $property = $this->entity->propertiesByName[$key];
        if ($property instanceof AttributeDescription) {
            $this->willAccessValueForKey($flag ? null : $key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if ($property instanceof DerivedAttributeDescription && !isset($this->resolvingKeys[$key]) && !$this->isSuppressingKVO && $this->isPropertyForKeyFault($key) && $this->isInserted) {
                $this->resolvingKeys[$key] = true;
                $value = self::coercedValue($property->derivationExpression?->expressionValue($this), $property->type, $property->attributeValueClassName, $property->valueTransformerName, $property->isOptional);
                $this->setPrimitiveValueForKey($value, $key);
                unset($this->resolvingKeys[$key]);
            }
            return $value;
        }
        if ($property instanceof FetchedPropertyDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if (!isset($this->resolvingKeys[$key]) && !$this->isSuppressingKVO && $this->isPropertyForKeyFault($key) && $this->isInserted) {
                $this->resolvingKeys[$key] = true;
                $value = $context->newValueForFetchedProperty($property, $this->objectID);
                $this->setPrimitiveValueForKey($value, $key);
                unset($this->resolvingKeys[$key]);
            }
            return $value ?? $this->mutableArrayValueForKey($key);
        }
        if ($property instanceof RelationshipDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if (!isset($this->resolvingKeys[$key]) && !$this->isSuppressingKVO && $this->isPropertyForKeyFault($key) && $this->isInserted) {
                $this->resolvingKeys[$key] = true;
                $value = $context->newValueForRelationship($property, $this->objectID);
                $this->setPrimitiveValueForKey($value, $key);
                unset($this->resolvingKeys[$key]);
            }
            if ($value instanceof FaultingArray || $value instanceof FaultingSet || $value instanceof ManagedObject) {
                return $value;
            }
            if ($value instanceof ManagedObjectID) {
                return $context->object($value);
            }
            if ($property->isToMany && !$property->isOptional) {
                return $this->mutableSetValueForKey($key);
            }
            return null;
        }
        return parent::valueForKey($key);
    }

    /**
     * Sets the specified rela$relationship of the managed object to the specified value.
     *
     * If $key is not a rela$relationship defined by the model or if it is not part of the receiver's properties, the method raises an exception. If $key identifies a to-one relationship, relates the object specified by value to the receiver, unrelating the previously related object if there was one. Given a collection object and a key that identifies a to-many relationship, relates the objects contained in the collection to the receiver, unrelating previously related objects if there were any.
     * This method is overridden by ManagedObject to access the managed object's generic dictionary storage unless the receiver's class explicitly provides key-value coding compliant accessor methods for $key.
     * @param mixed|null $value The new value for the rela$relationship specified by $key.
     * @param string $key The name of one of the receiver's properties.
     */
    #[Override]
    final public function setValueForKey(mixed $value, string $key): void
    {
        if (!$this->validateValueForKey($value, $key)) {
            return;
        }
        $this->updateDirtyState($value, $key);
        $property = $this->entity->propertiesByName[$key];
        if ($property instanceof AttributeDescription) {
            $value = $this->changedValuesForCurrentEvent[$key] ?? $value;
            if ($value instanceof Nil) {
                $value = $value->value;
            }
            $this->willChangeValueForKey($key, changedValue: $value);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key, changedValue: $value);
        } elseif ($property instanceof FetchedPropertyDescription) {
            $this->willChangeValueForKey($key, changedValue: $value);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key, changedValue: $value);
        } elseif ($property instanceof RelationshipDescription) {
            $inverseRelationship = $property->inverseRelationship;
            if ($property->isToMany) {
                $value instanceof Set ?: $value
                        |> typeof(...)
                        |> (fn(string $x): string => sprintf("invalid argument: expecting \"%s\", \"%s\" given", Set::class, $x))
                        |> fatal_error(...);
                $set = new FaultingSet($this, $property);
                $set->setSet($value);
                $value = $set;
                $change = $this->mutableSetValueForKey($key);
                if ($this->isStable && !$this->isSuppressingKVO && $this->isAwakeFromFetch && $this->hasFaultForRelationshipNamed($key) && $this->isInserted) {
                    /** @var FaultingSet $change */
                    $change = $this->valueForKey($key);
                    /** @var ManagedObject $managedObject */
                    foreach ($change as $managedObject) {
                        if ($member = $value->member($managedObject)) {
                            $managedObject->setValuesForKeys($member->dictionaryWithValues($member->serializationKeys));
                        }
                    }
                }
                $comparisonResult = $change->compare($value);
                if ($comparisonResult === ComparisonResult::orderedDescending) {
                    $change->subtract($value);
                    $changeKind = KeyValueChange::removal;
                } elseif ($comparisonResult === ComparisonResult::orderedAscending) {
                    $change->formUnion($value);
                    $changeKind = KeyValueChange::insertion;
                } else {
                    $changeKind = KeyValueChange::setting;
                    if (!$change->isEqual($value)) {
                        $difference = $change->filter(fn(ManagedObject $object): bool => !$value->containsElement($object));
                        $this->willChangeValueForKey($key, KeyValueChange::removal, $difference);
                        $change->formIntersection($value);
                        $this->didChangeValueForKey($key, KeyValueChange::removal, $difference);
                        $this->willChangeValueForKey($key, KeyValueChange::insertion, $change);
                        $change->formUnion($value);
                        $this->didChangeValueForKey($key, KeyValueChange::insertion, $change);
                        $changeKind = KeyValueChange::replacement;
                    }
                }
                if (!$inverseRelationship->isToMany) {
                    /** @var ManagedObject $managedObject */
                    foreach ($value as $managedObject) {
                        $managedObject->setPrimitiveValueForKey($this->objectID, $inverseRelationship->name);
                    }
                }
            } else {
                $value instanceof ManagedObject || $value instanceof ManagedObjectID || $value === null ?: $value
                        |> typeof(...)
                        |> (fn(string $x): string => sprintf("invalid argument: %s->%s expecting \"%s|%s|null\", \"%s\" given", $this->entityName, $key, ManagedObject::class, ManagedObjectID::class, $x))
                        |> fatal_error(...);
                $current = $this->primitiveValueForKey($key);
                if (!$this->isSuppressingKVO && !$this->isSuppressingChangeNotifications && $this->isAwakeFromFetch && $this->isInserted) {
                    $current = $this->valueForKey($key);
                }
                if ($current instanceof Value) {
                    $current = $current->value;
                }
                $current instanceof ManagedObject || $current instanceof ManagedObjectID || $current === null ?: $current
                        |> typeof(...)
                        |> (fn(string $x): string => sprintf("invalid argument: %s->%s expecting \"%s|%s|null\", \"%s\" given", $this->entityName, $key, ManagedObject::class, ManagedObjectID::class, $x))
                        |> fatal_error(...);
                if ($current === null && $value !== null) {
                    $changeKind = KeyValueChange::insertion;
                } elseif ($current !== null && $value === null) {
                    $changeKind = KeyValueChange::removal;
                } elseif (!$current?->isEqual($value)) {
                    $changeKind = KeyValueChange::replacement;
                } else {
                    $changeKind = KeyValueChange::setting;
                }
                if ($value instanceof ManagedObjectID) {
                    $value = $this->managedObjectContext->object($value);
                }
                if ($inverseRelationship->isToMany) {
                    $this->setPrimitiveValueForKey($value?->objectID, $property->name);
                } elseif ($value instanceof ManagedObject) {
                    $value->setPrimitiveValueForKey($this->objectID, $inverseRelationship->name);
                }
                $change = $value;
            }
            $this->willChangeValueForKey($key, $changeKind, $change);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key, $changeKind, $value);
        } elseif (property_exists($this, $key)) {
            $this->willChangeValueForKey($key, KeyValueChange::replacement, $value);
            $this->$key = $value;
            $this->didChangeValueForKey($key, KeyValueChange::replacement, $value);
        } else {
            parent::setValueForKey($value, $key);
        }
    }

    private function updateDirtyState(mixed $newValue, string $propertyName): void
    {
        if ($this->isSuppressingKVO || $this->isSuppressingChangeNotifications) {
            return;
        }
        $property = $this->persistentProperties[$propertyName];
        if (!$property instanceof PropertyDescription || $property instanceof DerivedAttributeDescription) {
            return;
        }
        $this->changedValuesForCurrentEvent[$propertyName] = $newValue ?? Nil::nil();
    }

    #[Override]
    public function dictionaryWithValues(ArrayClass $keys): Dictionary
    {
        return $keys->reduce(new Dictionary(),
            /**
             * @param Dictionary<mixed> $values
             * @param string $key
             * @return Dictionary<mixed>
             */
            function (Dictionary $values, string $key): Dictionary {
                $value = $this->valueForKey($key);
                $property = $this->entity->propertiesByName[$key];
                if ($property?->isSensitive) {
                    $value = new SensitiveValue($value);
                }
                $values[$key] = $value;
                return $values;
            });
    }

    /**
     * Returns the object IDs for all the managed objects that are in the named relationship.
     * @param string $key The name of the relationship.
     * @return ArrayClass<ManagedObjectID> An array of managed object ids.
     * @throws InternalInconsistencyException If $key is not a relationship defined by the model, the method raises an exception.
     */
    public function objectIDsForRelationshipNamed(string $key): ArrayClass
    {
        /** @var RelationshipDescription $relationship */
        $relationship = $this->entity->relationshipsByName[$key] ?? fatal_error(sprintf("%s %s() does not contains a relationship named \"%s\"", $this->debugDescription, __FUNCTION__, $key));
        $value = $relationship->isToMany ? $this->mutableSetValueForKey($key) : new Set([$this->primitiveValueForKey($key)]);
        return new ArrayClass($value->map(
        /**
         * @param ManagedObject|ManagedObjectID $e
         * @return ManagedObjectID
         */
            fn(ManagedObject|ManagedObjectID $e): ManagedObjectID => /** @var ManagedObjectID */
            $e instanceof ManagedObject ? $e->objectID : $e));
    }

    /**
     * @internal
     */
    public static function coercedValue(mixed $value, AttributeType $type, ?string $attributeValueClassName = null, ?string $valueTransformerName = null, bool $isOptional = true, bool $write = false): mixed
    {
        if ($value instanceof Value) {
            $value = $value->value;
        }
        $coercedValue = fn(string $type): string|int|bool|float|BackedEnum|null => match ($type) {
            "string" => $value instanceof BackedEnum ? $value : (string)$value,
            "int" => $value instanceof BackedEnum ? $value : (int)$value,
            "bool" => (function () use ($value, $write): bool|int {
                if ($value === null) {
                    $value = false;
                }
                if (!is_numeric($value)) {
                    $value = (bool)$value;
                }
                return $write ? new Number($value)->intValue : new Number($value)->boolValue;
            })(),
            "float", => (float)$value,
            default => $value
        };
        $optionalValue = fn(string $type): mixed => match (typeof($value)) {
            "null" => $isOptional ? null : $coercedValue($type),
            default => $coercedValue($type)
        };
        if ($type === AttributeType::uri && !$isOptional) {
            $value
                |> human_readable_value(...)
                |> (fn(string $x): string => sprintf("Warning: coercing null value to non-optional URI attribute, this will raise an exception in future versions of Core Data. Value: %s", $x))
                |> error_log(...);
            $isOptional = true;
        }
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        return match ($type) {
            AttributeType::integer16, AttributeType::integer32, AttributeType::integer64 => $optionalValue("int"),
            AttributeType::decimal, AttributeType::double, AttributeType::float => $optionalValue("float"),
            AttributeType::string => $optionalValue("string"),
            AttributeType::boolean => $optionalValue("bool"),
            AttributeType::date => match (true) {
                $value instanceof Date => $value,
                is_string($value) => $write ? $value : Date::dateWithTimeIntervalSince1970((float)strtotime($value)),
                is_null($value) => $isOptional ? null : new Date(),
                default => fatal_error(sprintf("Invalid argument: invalid value %s(%s) for type %s", human_readable_value($value), typeof($value), human_readable_value($type)))
            },
            AttributeType::uuid => match (true) {
                $value instanceof UUID => $value,
                is_string($value) => $write ? $value : new UUID($value),
                is_null($value) => $isOptional ? null : new UUID(),
                default => fatal_error(sprintf("Invalid argument: invalid value %s(%s) for type %s", human_readable_value($value), typeof($value), human_readable_value($type)))
            },
            AttributeType::uri => match (true) {
                $value instanceof URL => $value,
                is_string($value) => $write ? $value : new URL($value),
                is_null($value) => $isOptional ? null : $type
                        |> human_readable_value(...)
                        |> (fn(string $x): string => sprintf("Invalid argument: attribute type \"%s\" cannot be initialized with a null argument", $x))
                        |> fatal_error(...),
                default => fatal_error(sprintf("Invalid argument: invalid value %s(%s) for type %s", human_readable_value($value), typeof($value), human_readable_value($type)))
            },
            AttributeType::transformable, AttributeType::objectID => (function () use ($value, $attributeValueClassName, $isOptional, $write, $valueTransformerName): mixed {
                if ($value === null && !$isOptional && $attributeValueClassName !== null && class_exists($attributeValueClassName) && $attributeValueClassName !== ManagedObjectID::class) {
                    $value = new $attributeValueClassName();
                }
                if ($transformer = ValueTransformer::valueTransformerForName($valueTransformerName ?? SecureUnarchiveFromDataTransformerName)) {
                    return $write ? $transformer->transformedValue($value) : $transformer->reverseTransformedValue($value);
                }
                return $value;
            })(),
            AttributeType::undefined => fatal_error("Invalid argument: cannot use an attribute type of \"Undefined\""),
            default => $value
        };
    }

    /**
     * @internal
     */
    public static function coerceValue(mixed &$value, PropertyDescription $property, bool $write = false): bool
    {
        if ($value instanceof Value) {
            $value = $value->value;
        }
        if ($property instanceof AttributeDescription) {
            $type = $property->type;
            $attributeValueClassName = $property->attributeValueClassName;
            $valueTransformerName = $property->valueTransformerName;
            $isOptional = $property->isOptional;
            if ($value === null) {
                if (!$isOptional) {
                    $value = self::coercedValue($property->defaultValue, $type, $attributeValueClassName, $valueTransformerName, $isOptional, $write);
                }
            } else {
                $value = match ($type) {
                    AttributeType::string, AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float, AttributeType::boolean => $isOptional && $value === "" ? null : self::coercedValue($value, $type, $attributeValueClassName, $valueTransformerName, $isOptional, $write),
                    default => self::coercedValue($value, $type, $attributeValueClassName, $valueTransformerName, $isOptional, $write),
                };
                if (!match ($type) {
                        AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float => is_int($value) || is_float($value) || $value instanceof Number || $value instanceof BackedEnum,
                        AttributeType::string, AttributeType::binaryData => is_string($value) || $value instanceof BackedEnum,
                        AttributeType::boolean => is_bool($value) || is_int($value) || $value instanceof Number,
                        AttributeType::date, AttributeType::uuid, AttributeType::uri => is_string($value) || $value instanceof Date || $value instanceof UUID || $value instanceof URL,
                        AttributeType::transformable => $write ? is_string($value) : ($attributeValueClassName === null || (class_exists($attributeValueClassName) && is_a($value, $attributeValueClassName, true))),
                        AttributeType::compositeAttributeType => $value instanceof Dictionary,
                        default => false,
                    } && !$property->isOptional) {
                    $value
                        |> human_readable_value(...)
                        |> (fn(string $x): string => sprintf("Invalid argument: %s \"%s\", expecting \"%s\", (%s)\"%s\" given", $property->entity->name, $property->name, $type->name, typeof($value), $x))
                        |> fatal_error(...);
                }
            }
        } elseif ($property instanceof FetchedPropertyDescription) {
            $value ??= new ArrayClass();
        } elseif ($property instanceof RelationshipDescription) {
            if ($property->isToMany) {
                if ($value instanceof ArrayClass) {
                    $value = new Set($value);
                }
                $value ??= new Set();
            }
        }
        return true;
    }

    /**
     * Validates a rela$relationship value for a given key.
     *
     * This method is responsible for two things: coercing the value into an appropriate type for the object and validating it according to the object's rules.
     * The default implementation provided by ManagedObject consults the object's entity description to coerce the value and to check for basic errors, such as a null value when that isn't allowed and the length of strings when a field width is specified for the attribute.
     * It then searches for a method of the form validate<Key>() and invokes it if it exists.
     * You can implement methods of the form validate<Key>() to perform validation that is not possible using the constraints available in the rela$relationship description. If it finds an unacceptable value, your validation method should return false and error that describes the problem. For inter-rela$relationship validation (to check for combinations of values that are invalid), see {@see validateForUpdate()} and related methods.
     * @param mixed|null $value A pointer to an object.
     * @param string $key The name of one of the receiver's properties.
     * @return bool true if value is a valid value for $key (or if value can be coerced into a valid value for $key), otherwise false. If $value is not a valid value for $key (and cannot be coerced), the method raises an exception.
     */
    #[Override]
    public function validateValueForKey(mixed &$value, string $key): bool
    {
        if (!parent::validateValueForKey($value, $key)) {
            return false;
        }
        if ($property = $this->entity->propertiesByName[$key]) {
            return self::coerceValue($value, $property);
        }
        if (property_exists($this, $key)) {
            if ($key === ManagedObjectObjectIDKey && (is_int($value) || is_string($value))) {
                $this->objectID->referenceObject = $value;
                return false;
            }
            return true;
        }
        $store = $this->managedObjectContext->persistentStoreCoordinator?->persistentStoreForObject($this);
        if ($store instanceof SQLCore) {
            /** @var SQLEntity $entity */
            $entity = $store->model->entitiesByName[$this->entity->name];
            if ($entity->propertiesByName[$key]) {
                return match ($key) {
                    ManagedObjectEntityNameKey => false,
                    default => true
                };
            }
        }
        return true;
    }

    /**
     * @throws Exception
     */
    private function validateChangedValues(): void
    {
        !$this->changedValues->isEmpty ?: fatal_error("invalid state: changed values is empty");
        $properties = new Set($this->changedValues->keys->compactMap(fn(string $key): ?PropertyDescription => $this->entity->propertiesByName[$key]));
        $properties->formUnion($this->persistentProperties->filter(fn(PropertyDescription $property): bool => !$property->isOptional));
        foreach ($properties as $property) {
            $key = $property->name;
            $value = $this->changedValues[$key];
            if ($value instanceof Nil) {
                continue;
            }
            if (!($predicate = $property->validationPredicates->first(fn(Predicate $predicate) => !$predicate->evaluate($this)))) {
                continue;
            }
            $error = new Error(CoreDataErrorDomain, ManagedObjectConstraintValidationError, new Dictionary([LocalizedDescriptionKey => localized_string("Constraint Violation"), LocalizedFailureReasonErrorKey => sprintf(localized_string("The value being assigned does not satisfy the constraints (%s) defined for property \"%s\" on entity \"%s\"."), $predicate, $property->localizedName, $this->entity->localizedName), ValidationObjectErrorKey => $this, ValidationValueErrorKey => $value, ValidationKeyErrorKey => $key, ValidationPredicateErrorKey => $predicate]));
            throw new InternalInconsistencyException(error: $error);
        }
    }

    /**
     * Determines whether the managed object can be deleted in its current state.
     *
     * An object cannot be deleted if it has a relationship has a “deny” delete rule and that relationship has a destination object.
     * ManagedObject's implementation sends the receiver's entity description a message which performs basic checking based on the presence or absence of values.
     * @throws Exception If the receiver cannot be deleted in its current state, the method raises an exception.
     */
    public function validateForDelete(): void
    {
    }

    /**
     * Determines whether the managed object can be inserted in its current state.
     *
     * Subclasses should invoke super's implementation before performing their own validation and should combine any error returned by super's implementation with their own (see Managed Object Validation).
     * @throws Exception If the receiver cannot be inserted in its current state, the method raises an exception.
     */
    public function validateForInsert(): void
    {
        $this->validateChangedValues();
    }

    /**
     * Determines whether the managed object's current state is valid.
     *
     * ManagedObject's implementation iterates through all the receiver's properties, validating each in turn. If this results in more than one error, the userInfo dictionary in the Error returned in error contains a key DetailedErrorsKey; the corresponding value is an array containing the individual validation errors. If you pass NULL as the error, validation will abort after the first failure.
     * @throws Exception If the receiver's current state is invalid, the method raises an exception.
     */
    public function validateForUpdate(): void
    {
        $this->validateChangedValues();
    }

    /**
     * Provides support for key-value observing access notification.
     *
     * Together with {@see willAccessValueForKey()}, this method is used to fire faults, to maintain inverse relationships, and so on.
     * Each read access must be wrapped in this method pair (in the same way that each write access must be wrapped in the {@see willChangeValueForKey()}/{@see didChangeValueForKey()} method pair).
     * In the default implementation of ManagedObject these methods are invoked for you automatically.
     * If, say, you create a custom subclass that uses explicit instance variables, you must invoke them yourself, as in the following example.
     * <code>
     * public function firstName(): string
     * {
     *     $this->willAccessValueForKey("firstName");
     *     $firstName = $this->firstName;
     *     $this->didAccessValueForKey("firstName");
     *     return $firstName;
     * }
     * </code>
     * @param string|null $key The name of one of the receiver's properties.
     */
    public function didAccessValueForKey(?string $key): void
    {
    }

    /**
     * Provides support for key-value observing access notification.
     *
     * See {@see didAccessValueForKey()} for more details.
     * You can invoke this method with the key value of null to ensure that a fault has been fired, as illustrated by the following example.
     * <code>
     * $managedObject->willAccessValueForKey(null);
     * </code>
     * @param string|null $key The name of one of the receiver's properties.
     */
    public function willAccessValueForKey(?string $key): void
    {
        if ($key === null) {
            $this->faultHandler->fulfillFault($this);
        }
    }

    #[Override]
    public function willChangeValueForKey(string $key, KeyValueChange $changeKind = KeyValueChange::setting, mixed $changedValue = null): void
    {
        if (!$this->isSuppressingKVO) {
            parent::willChangeValueForKey($key, $changeKind, $changedValue);
        }
    }

    #[Override]
    public function didChangeValueForKey(string $key, KeyValueChange $changeKind = KeyValueChange::setting, mixed $changedValue = null): void
    {
        if (!$this->isSuppressingKVO) {
            parent::didChangeValueForKey($key, $changeKind, $changedValue);
        }
    }

    private function serializedRelationshipValue(RelationshipDescription $relationship): Set|Dictionary|null
    {
        $key = $relationship->name;
        $value = $this->valueForKey($key);
        if ($value instanceof ManagedObject) {
            return $value->jsonSerialize();
        }
        if ($value instanceof Set) {
            return $value->map(fn(ManagedObject $object): Dictionary => $object->jsonSerialize());
        }
        if ($relationship->isToMany && !$relationship->isOptional) {
            return new Set();
        }
        return null;
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        return $this->serializationKeys->reduce(new Dictionary(),
            /**
             * @param Dictionary<mixed> $dictionary
             * @param string $key
             * @return Dictionary<mixed>
             */
            function (Dictionary $dictionary, string $key): Dictionary {
                if ($property = $this->entity->propertiesByName[$key]) {
                    if ($property instanceof AttributeDescription) {
                        $value = $this->valueForKey($key) ?? Nil::nil();
                        if ($property->isSensitive) {
                            $value = new SensitiveValue($value);
                        }
                        $dictionary[$key] = $value;
                    } elseif ($property instanceof RelationshipDescription) {
                        if (!($value = $this->serializedRelationshipValue($property))) {
                            $value = $property->isOptional ? Nil::nil() : ($property->isToMany ? new Set() : fatal_error(sprintf("%s relationship \"%s\" is not optional", $this->debugDescription, $property->name)));
                        }
                        $dictionary[$key] = $value;
                    } else {
                        $dictionary[$key] = $this->valueForKey($key);
                    }
                } else {
                    $dictionary[$key] = $this->valueForKey($key) ?? Nil::nil();
                }
                return $dictionary;
            });
    }

    /**
     * Configures the object and its reachable relationships according to a given serialization shape.
     *
     * When provided with a nested dictionary describing the desired properties and relationships,
     * this method:
     *   1. Updates the object's `serializationRule` to `custom`.
     *   2. Sets `serializationKeys` to include exactly the specified properties.
     *   3. Recursively applies the same rules to related ManagedObjects, ensuring the entire object graph
     *      conforms to the requested shape.
     *
     * The resulting object graph is fully prepared for JSON serialization via `jsonSerialize()`.
     *
     * If no serialization shape is provided (null or empty), the object is returned unchanged.
     *
     * @param Dictionary<mixed>|null $serialization Nested dictionary specifying the properties and relationships to include.
     *
     * @return static The same object instance, with serialization rules applied.
     */
    public function serialized(?Dictionary $serialization = null): static
    {
        return ManagedObjectSerializationPreparer::shared()->serialized($this, $serialization);
    }

    #[Override]
    final public function isEqual(mixed $other): bool
    {
        if ($other instanceof ManagedObject && $this->entity->isKindOf($other->entity)) {
            return $this->objectID->isEqual($other->objectID);
        }
        return false;
    }
}
