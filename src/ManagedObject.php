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
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionType;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\SensitivePropertyValue;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\Value;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\human_readable_value;
use function Sabatier\Foundation\typeof;
use function Sabatier\Foundation\uuid_generate;
use const Sabatier\Foundation\CocoaErrorDomain;
use const Sabatier\Foundation\KeyValueValidationError;
use const Sabatier\Foundation\NotFound;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/**
 * A base class that implements the behavior required of a Core Data model object.
 */
class ManagedObject extends ObjectClass implements FetchRequestResult
{
    use FaultingMutableSetMutationMethods;

    /** @var bool A Boolean value that indicates whether to mark instances of the class as having changes when an unmodeled property changes. false if instances of the class should be marked as having changes if an unmodeled property is changed, otherwise true. The default value is true. */
    public static bool $contextShouldIgnoreUnmodeledPropertyChanges = true;
    /** @var EntityDescription The entity description of the managed object. */
    public readonly EntityDescription $entity;
    /** @var ManagedObjectID The object ID of the managed object. If the receiver is a fault, accessing this property does not cause it to fire. If the receiver has not yet been saved, the object ID is a temporary value that will change when the object is saved. */
    public ManagedObjectID $objectID;
    /** @var bool A Boolean value that indicates whether the managed object has been inserted in a managed object context. */
    public readonly bool $isInserted;
    /** @var bool A Boolean value that indicates whether the managed object has unsaved changes. */
    public readonly bool $isUpdated;
    /** @var bool A Boolean value that indicates whether the managed object will be deleted during the next save. */
    public readonly bool $isDeleted;
    public readonly ManagedObjectContext $managedObjectContext;
    private Dictionary $changedValues;
    private Dictionary $changedValuesForCurrentEvent;
    /** @var bool A Boolean value that indicates whether the managed object is a fault. Knowing whether an object is a fault is useful in many situations when computations are optional. It can also be used to avoid growing the object graph unnecessarily (which may improve performance as it can avoid time-consuming fetches from data stores). If this property is false, then the receiver's data must be in memory. However, if this property is true, it does not mean that the data is not in memory. The data may be in memory, or it may not, depending on many factors influencing caching. If the receiver is a fault, accessing this property does not cause it to fire. */
    public bool $isFault = true;
    /** @var int The faulting state of the managed object. 0 if the object is fully initialized as a managed object and not transitioning to or from another state, otherwise some other value. */
    public int $faultingState = NotFound;
    public SerializationRule $serializationRule = SerializationRule::attributesAndRelationships;
    /** @var ArrayClass<string> */
    public ArrayClass $serializationKeys;
    private array $reserved = [];
    /** @internal */
    public readonly FaultHandler $faultHandler;
    /** @internal */
    public readonly ArrayClass $allProperties;
    /** @internal */
    public readonly ArrayClass $modeledProperties;
    /** @internal */
    public readonly ArrayClass $persistentProperties;
    /** @internal */
    public readonly ArrayClass $transientProperties;
    /** @internal */
    public bool $isSuppressingKVO = false;
    /** @internal */
    public bool $isSuppressingChangeNotifications = false;
    /** @internal */
    public bool $isAwakening = false;
    /** @internal */
    public readonly string $entityName;
    public string $description {
        get => sprintf("<%s %s> (entity: %s; id: %s %s; data: %s)", $this->entity->name, $this->hash, $this->entity->name, $this->objectID->hash, $this->objectID->description, $this->isFault ? "<fault>" : $this->dictionaryWithValues($this->entity->propertiesByName->filter(fn(PropertyDescription $property): bool => !$this->isRelationshipForKeyFault($property->name))->keys)->description);
    }
    public string $debugDescription {
        get => sprintf("<%s: %s>", get_called_class(), $this->hash);
    }

    /**
     * Initializes a managed object from an entity description and inserts it into the specified managed object context.
     * @param ManagedObjectContext $managedObjectContext The context into which the new instance is inserted.
     * @param EntityDescription|null $entity The entity of which to create an instance.
     * The model associated with context's persistent store coordinator must contain entity.
     * If the receiver is a fault, accessing this property does not cause it to fire.
     */
    public function __construct(ManagedObjectContext $managedObjectContext, ?EntityDescription $entity = null)
    {
        unset($this->serializationKeys);
        unset($this->faultHandler);
        unset($this->allProperties);
        unset($this->modeledProperties);
        unset($this->persistentProperties);
        unset($this->transientProperties);
        unset($this->changedValues);
        unset($this->changedValuesForCurrentEvent);
        unset($this->objectID);
        unset($this->isInserted);
        unset($this->isUpdated);
        unset($this->isDeleted);
        if ($this->isSubclass(ManagedObject::class)) {
            $entity ??= static::entity();
        }
        $this->managedObjectContext = $managedObjectContext;
        $this->entity = $entity ?? fatal_error("Invalid argument: entity cannot be null");
        $this->entityName = $this->entity->name;
        $this->managedObjectContext->insert($this);
    }

    public function __get(string $name)
    {
        if ($name === "objectID") {
            $this->$name = new ManagedObjectID($this->entity, uuid_generate());
            return $this->$name;
        }
        if ($name === "changedValues" || $name === "changedValuesForCurrentEvent") {
            $this->$name = new Dictionary();
            return $this->$name;
        }
        if ($name === "faultHandler") {
            $this->$name = ($this->managedObjectContext->persistentStoreCoordinator?->persistentStoreForObject($this) ?? fatal_error("Persistent store coordinator cannot be null"))->faultHandler;
            return $this->$name;
        }
        if ($name === "allProperties") {
            $this->$name = $this->entity->properties;
            return $this->$name;
        }
        if ($name === "modeledProperties") {
            $this->$name = $this->allProperties;
            return $this->$name;
        }
        if ($name === "persistentProperties") {
            $this->$name = $this->modeledProperties->filter(fn(PropertyDescription $property): bool => !$property->isTransient && !$property instanceof DerivedAttributeDescription && !$property instanceof FetchedPropertyDescription);
            return $this->$name;
        }
        if ($name === "transientProperties") {
            $this->$name = $this->modeledProperties->filter(fn(PropertyDescription $property): bool => $property->isTransient);
            return $this->$name;
        }
        if ($name === "serializationKeys") {
            /** @var ArrayClass<string> $serializationKeys */
            $serializationKeys = match ($this->serializationRule) {
                SerializationRule::attributesOnly => $this->entity->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$attribute->isTransient)->keys,
                SerializationRule::attributesAndRelationships => $this->entity->propertiesByName->filter(function (PropertyDescription $property): bool {
                    if ($property instanceof AttributeDescription) {
                        return !$property->isTransient;
                    }
                    if ($property instanceof RelationshipDescription) {
                        return $property->isToMany && !$property->inverseRelationship->isToMany;
                    }
                    return $property instanceof FetchedPropertyDescription;
                })->keys,
                default => new ArrayClass(),
            };
            $serializationKeys->insertAt(SQLEntity::primaryKeyName, 0);
            $serializationKeys->insertAt(SQLEntity::primaryKeyName, 0);
            $this->$name = $serializationKeys;
            return $this->$name;
        }
        if ($name === "isInserted") {
            $isInserted = false;
            if ($persistentStore = $this->objectID->persistentStore) {
                /** @var FetchRequest<Number> $fetchRequest */
                $fetchRequest = $this::fetchRequest();
                $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(SQLEntity::primaryKeyName), Expression::expressionForConstantValue($this->objectID));
                $fetchRequest->affectedStores = new ArrayClass([$persistentStore]);
                /** @noinspection PhpUnhandledExceptionInspection */
                $isInserted = (bool)$this->managedObjectContext->count($fetchRequest);
            }
            $this->$name = $isInserted;
            return $this->$name;
        }
        if ($name === "isUpdated") {
            $this->$name = $this->isInserted && !$this->changedValuesForCurrentEvent->isEmpty;
            return $this->$name;
        }
        if ($name === "isDeleted") {
            $this->$name = $this->managedObjectContext->deletedObjects->containsElement($this);
            return $this->$name;
        }
        return $this->valueForKey($name);
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === "changedValues" || $name === "changedValuesForCurrentEvent" || $name === "serializationKeys" || $name === "allProperties" || $name === "modeledProperties" || $name === "persistentProperties" || $name === "transientProperties" || $name === "faultHandler" || $name === "isInserted" || $name === "isUpdated" || $name === "isDeleted") {
            $this->$name = $value;
        } else {
            $this->setValueForKey($value, $name);
        }
    }

    public function __isset(string $name): bool
    {
        return isset($this->changedValues[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->changedValues[$name]);
    }

    /**
     * Returns the entity description that is associated with this subclass.
     *
     * This method is only legal to call on subclasses of ManagedObject that represent a single entity in the model.
     * @return EntityDescription
     */
    public static function entity(): EntityDescription
    {
        return static::staticAssociatedValueForKey(__FUNCTION__) ?? fatal_error(sprintf("Class \"%s\" not found", static::class));
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
        if ($this->entity->relationshipsByName[$key]) {
            return $this->isRelationshipForKeyFault($key);
        }
        fatal_error("This class does not contains a relationship named \"$key\"");
    }

    /**
     * @internal
     */
    public function isRelationshipForKeyFault(string $key): bool
    {
        $value = $this->primitiveValueForKey($key);
        $property = $this->entity->propertiesByName[$key];
        if (($property instanceof FetchedPropertyDescription && $value instanceof FaultingMutableArray) || ($property instanceof RelationshipDescription && $value instanceof FaultingMutableSet)) {
            return $value->isFault;
        }
        return !isset($this->reserved[$key]) && !isset($this->changedValues[$key]);
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
     * You typically use this method to initialize special default property values. This method is invoked only once in the object's lifetime. If you want to set attribute values in an implementation of this method, you should typically use primitive accessor methods (either {@see setPrimitiveValueForKey()} or better the appropriate custom primitive accessors). This ensures that the new values are treated as baseline values rather than being recorded as undoable changes for the properties in question. Subclasses must invoke super's implementation before performing their own initialization.
     */
    public function awakeFromInsert(): void
    {
        /** @var PropertyDescription $property */
        foreach ($this->modeledProperties as $property) {
            $key = $property->name;
            $value = $this->primitiveValueForKey($key);
            if ($property instanceof AttributeDescription && !$property instanceof DerivedAttributeDescription) {
                $value ??= $property->defaultValue;
                if ($value === null && !$property->isOptional) {
                    $value = match ($property->type) {
                        AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float, AttributeType::string, AttributeType::boolean => self::coercedValue($value, $property->type, $property->attributeValueClassName, $property->valueTransformerName, $property->isOptional),
                        default => null
                    };
                }
            } elseif ($property instanceof RelationshipDescription) {
                if ($property->isToMany) {
                    if ($this->isSubclass(ManagedObject::class)) {
                        $this->createMutationMethods($key);
                    }
                    $value ??= new FaultingMutableSet($this, $property);
                }
            }
            $this->setPrimitiveValueForKey($value, $key);
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
     * @return Dictionary A dictionary with keys that are the names of persistent properties with changes since last fetching or saving the receiver, and with the new values for those properties.
     */
    public function changedValues(): Dictionary
    {
        /** @var ArrayClass<string> $keys */
        $keys = $this->persistentProperties->valueForKey("name");
        return $this->changedValues->filter(fn(mixed $value, string $key): bool => $keys->containsElement($key));
    }

    /**
     * Returns a dictionary containing the keys and new values of persistent properties with changes since the last fetching or saving of the managed object.
     *
     * This method only reports changes to properties that are persistent properties of the receiver, not changes to transient properties or custom instance variables.
     * @return Dictionary A dictionary with keys that are the names of persistent properties with changes since the last posting of {@see ManagedObjectContextObjectsDidChange}, and with the new values for those properties.
     */
    public function changedValuesForCurrentEvent(): Dictionary
    {
        return $this->changedValuesForCurrentEvent;
    }

    /**
     * Returns a dictionary of the most recent fetched or saved values of the managed object for the properties of the specified keys. nil values are represented by {@see Nil}.
     *
     * This method only reports values of properties that are defined as persistent properties of the receiver, not values of transient properties or of custom instance variables.
     * You can invoke this method with the keys value of nil to retrieve committed values for all the receiver's properties, as illustrated by the following example.
     * <code>
     * $allCommittedValues = $managedObject->committedValuesForKeys(null);
     * </code>
     * It is more efficient to use nil than to pass an array of all the property keys.
     * @param ArrayClass<string>|null $keys An array containing names of properties of the receiver, or nil.
     * @return Dictionary A dictionary containing the last fetched or saved values of the receiver for the properties specified by keys.
     */
    public function committedValuesForKeys(?ArrayClass $keys): Dictionary
    {
        $values = $this->changedValuesForCurrentEvent;
        if ($keys === null) {
            return $values;
        }
        return $values->filter(fn(mixed $value, string $key): bool => $keys->containsElement($key));
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object before deleting it.
     */
    public function prepareForDeletion(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object before saving it.
     */
    public function willSave(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object after the managed object's context completes a save operation.
     */
    public function didSave(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object before converting it to a fault.
     *
     * This method is the companion of the {@see didTurnIntoFault()} method. You can use it to (re)set state which requires access to property values (for example, observers across key paths).
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

    #[Override]
    public function mutableSetValueForKey(string $key): Set
    {
        if (!($relationship = $this->entity->relationshipsByName[$key])) {
            return $this->valueForUndefinedKey($key);
        }
        if (!$relationship->isToMany) {
            fatal_error("$this->debugDescription does not contains a to many relationship named \"$key\"");
        }
        $mutableSet = $this->primitiveValueForKey($key);
        if (!$mutableSet instanceof FaultingMutableSet) {
            $set = new FaultingMutableSet($this, $relationship);
            if ($mutableSet instanceof Set) {
                $set->formUnion($mutableSet);
            }
            $this->setPrimitiveValueForKey($set, $key);
            $mutableSet = $set;
        }
        return $mutableSet;
    }

    #[Override]
    public function mutableArrayValueForKey(string $key): ArrayClass
    {
        if (!($property = $this->entity->propertiesByName[$key])) {
            return $this->valueForUndefinedKey($key);
        }
        $mutableArray = $this->primitiveValueForKey($key);
        if (!$mutableArray instanceof FaultingMutableArray) {
            $array = new FaultingMutableArray($this, $property);
            if ($mutableArray instanceof ArrayClass) {
                /** @psalm-suppress InvalidArgument */
                $array->appendContentsOf($mutableArray);
            }
            $this->setPrimitiveValueForKey($array, $key);
            $mutableArray = $array;
        }
        return $mutableArray;
    }

    /**
     * Returns the value for the specified property from the managed object's private internal storage.
     *
     * This method does not invoke the access notification methods ({@see willAccessValueForKey()} and {@see didAccessValueForKey()}).
     * This method is used primarily by subclasses that implement custom accessor methods that need direct access to the receiver's private storage.
     * @param string $key The name of one of the receiver's properties.
     * @return mixed The value of the property specified by key. Returns nil if no value has been set.
     */
    final public function primitiveValueForKey(string $key): mixed
    {
        return $this->changedValues[$key];
    }

    /**
     * Sets the value of a given property in the managed object's private internal storage.
     *
     * Sets in the receiver's private internal storage the value of the property specified by key to value.
     * If key identifies a to-one relationship, relates the object specified by value to the receiver, unrelating the previously related object if there was one. Given a collection object and a key that identifies a to-many relationship, relates the objects contained in the collection to the receiver, unrelating previously related objects if there were any.
     * This method does not invoke the change notification methods ({@see willChangeValueForKey()} and {@see didChangeValueForKey()}).
     * It is typically used by subclasses that implement custom accessor methods that need direct access to the receiver's private internal storage. It is also used by the Core Data framework to initialize the receiver with values from a persistent store or to restore a value from a snapshot.
     * @param mixed|null $value The new value for the property specified by key.
     * @param string $key The name of one of the receiver's properties.
     */
    final public function setPrimitiveValueForKey(mixed $value, string $key): void
    {
        $this->changedValues[$key] = $value;
    }

    /**
     * Returns the value for the property specified by key.
     *
     * If key is not a property defined by the model, the method raises an exception.
     * This method is overridden by ManagedObject to access the managed object's generic dictionary storage unless the receiver's class explicitly provides key-value coding compliant accessor methods for key.
     * @param string $key The name of one of the receiver's properties.
     * @return mixed The value of the property specified by key.
     * @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection
     */
    #[Override]
    final public function valueForKey(string $key): mixed
    {
        if (empty($key)) {
            return $this->valueForUndefinedKey($key);
        }
        $context = $this->managedObjectContext;
        $property = $this->entity->propertiesByName[$key];
        if ($property instanceof AttributeDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if ($property instanceof DerivedAttributeDescription && !$value && !isset($this->reserved[$key]) && $this->isRelationshipForKeyFault($key)) {
                $this->reserved[$key] = true;
                $value = self::coercedValue($property->derivationExpression?->expressionValue($this), $property->type, $property->attributeValueClassName, $property->valueTransformerName, $property->isOptional);
                $this->setPrimitiveValueForKey($value, $key);
                //unset($this->reserved[$key]);
            }
            return $value;
        }
        if ($property instanceof FetchedPropertyDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if (!isset($this->reserved[$key]) && $this->isRelationshipForKeyFault($key)) {
                $this->reserved[$key] = true;
                $value ??= new FaultingMutableArray($this, $property);
                if ($property->fetchRequest !== null) {
                    $fetchRequest = clone $property->fetchRequest;
                    if ($entityName = $fetchRequest->entityName) {
                        $fetchRequest->entity = EntityDescription::entity($entityName, $context);
                    }
                    if ($predicate = $fetchRequest->predicate) {
                        $fn = function (CompoundPredicate|ComparisonPredicate $predicate) use (&$fn, $property): CompoundPredicate|ComparisonPredicate {
                            if ($predicate instanceof ComparisonPredicate) {
                                $expression = function (Expression $expression) use ($property): Expression {
                                    if (($expression->expressionType === ExpressionType::variable) || (($expression->expressionType === ExpressionType::keyPath) && $expression->operand?->expressionType === ExpressionType::variable)) {
                                        return Expression::expressionForConstantValue($expression->expressionValue($this, new Dictionary(["\$FETCH_SOURCE" => $this, "\$FETCHED_PROPERTY" => $property])));
                                    }
                                    return $expression;
                                };
                                $leftExpression = $expression($predicate->leftExpression);
                                $rightExpression = $expression($predicate->rightExpression);
                                if ($leftExpression !== $predicate->leftExpression || $rightExpression !== $predicate->rightExpression) {
                                    return new ComparisonPredicate($leftExpression, $rightExpression, $predicate->predicateOperatorType, $predicate->comparisonPredicateModifier, $predicate->options);
                                }
                                return $predicate;
                            }
                            /** @psalm-suppress all */
                            return new CompoundPredicate($predicate->compoundPredicateType, $predicate->subpredicates->map(fn(CompoundPredicate|ComparisonPredicate $subpredicate): CompoundPredicate|ComparisonPredicate => $fn($subpredicate)));
                        };
                        /** @psalm-suppress ArgumentTypeCoercion */
                        $fetchRequest->predicate = $fn($predicate);
                    }
                    $value->setArray($context->fetch($fetchRequest));
                }
                $this->setPrimitiveValueForKey($value, $key);
                //unset($this->reserved[$key]);
            }
            return $value;
        }
        if ($property instanceof RelationshipDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if (!isset($this->reserved[$key]) && $this->isRelationshipForKeyFault($key)) {
                $this->reserved[$key] = true;
                $store = $context->persistentStoreCoordinator?->persistentStoreForObject($this) ?? fatal_error("Persistent store coordinator cannot be null");
                $newValue = $store->newValueForRelationship($property, $this->objectID, $context);
                if ($property->isToMany) {
                    $value ??= new FaultingMutableSet($this, $property);
                    $value->setSet(new Set($newValue));
                } else {
                    $value = $newValue;
                }
                $this->setPrimitiveValueForKey($value, $key);
                //unset($this->reserved[$key]);
            }
            if ($value instanceof FaultingMutableArray || $value instanceof FaultingMutableSet || $value instanceof ManagedObject) {
                return $value;
            }
            if ($value instanceof ManagedObjectID) {
                return $context->object($value);
            }
            if ($property->isToMany && !$property->isOptional) {
                return new Set();
            }
            return null;
        }
        return parent::valueForKey($key);
    }

    /**
     * Sets the specified property of the managed object to the specified value.
     *
     * If key is not a property defined by the model or if is not part of the receiver's properties, the method raises an exception. If key identifies a to-one relationship, relates the object specified by value to the receiver, unrelating the previously related object if there was one. Given a collection object and a key that identifies a to-many relationship, relates the objects contained in the collection to the receiver, unrelating previously related objects if there were any.
     * This method is overridden by ManagedObject to access the managed object's generic dictionary storage unless the receiver's class explicitly provides key-value coding compliant accessor methods for key.
     * @param mixed|null $value The new value for the property specified by key.
     * @param string $key The name of one of the receiver's properties.
     */
    #[Override]
    final public function setValueForKey(mixed $value, string $key): void
    {
        if (!$this->validateValueForKey($value, $key)) {
            return;
        }
        /** @var PropertyDescription|null $property */
        $property = $this->entity->propertiesByName[$key];
        if ($property instanceof PropertyDescription && !$property->isTransient && !$property instanceof DerivedAttributeDescription && !$property instanceof FetchedPropertyDescription && !$this->isSuppressingKVO && !$this->isSuppressingChangeNotifications) {
            $this->changedValuesForCurrentEvent[$key] = $value ?? Nil::nil();
        }
        if ($property instanceof AttributeDescription || $property instanceof FetchedPropertyDescription) {
            $this->willChangeValueForKey($key, changedValue: $value);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key, changedValue: $value);
        } elseif ($property instanceof RelationshipDescription) {
            $inverseRelationship = $property->inverseRelationship;
            if ($property->isToMany) {
                assert($value instanceof Set, sprintf("invalid argument: expecting \"%s\", \"%s\" given", Set::class, typeof($value)));
                $set = new FaultingMutableSet($this, $property);
                $set->setSet($value);
                $value = $set;
                $change = $this->mutableSetValueForKey($key);
                if (!$this->isAwakening && $this->isInserted && $this->isRelationshipForKeyFault($key)) {
                    $this->reserved[$key] = true;
                    /** @var FaultingMutableSet $change */
                    $change = $this->valueForKey($key);
                    /** @var ManagedObject $managedObject */
                    foreach ($change as $managedObject) {
                        if ($member = $value->member($managedObject)) {
                            $managedObject->setValuesForKeys($member->dictionaryWithValues($member->persistentProperties->valueForKey("name")));
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
                assert($value instanceof ManagedObject || $value instanceof ManagedObjectID || $value === null, sprintf("invalid argument: %s(%s) expecting \"%s|%s|null\", \"%s\" given", $this->entity->name, $key, ManagedObject::class, ManagedObjectID::class, typeof($value)));
                $change = $value;
                $current = $this->primitiveValueForKey($key);
                if ($this->isInserted && $this->isRelationshipForKeyFault($key)) {
                    $current = $this->valueForKey($key);
                }
                if ($current === null && $value !== null) {
                    $changeKind = KeyValueChange::insertion;
                } elseif ($current !== null && $value === null) {
                    $changeKind = KeyValueChange::removal;
                    $change = $current;
                } else {
                    $changeKind = KeyValueChange::replacement;
                }
                if ($value instanceof ManagedObjectID) {
                    $value = $this->managedObjectContext->object($value);
                }
                if ($inverseRelationship->isToMany) {
                    $this->setPrimitiveValueForKey($value?->objectID, $property->name);
                } elseif ($value instanceof ManagedObject) {
                    $value->setPrimitiveValueForKey($this->objectID, $inverseRelationship->name);
                }
            }
            $this->willChangeValueForKey($key, $changeKind, $change);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key, $changeKind, $value);
        } elseif (property_exists($this, $key)) {
            if (!isset($this->$key)) {
                $this->$key = $value;
            }
        } else {
            parent::setValueForKey($value, $key);
        }
    }

    #[Override]
    final public function setValuesForKeys(Dictionary $keyedValues): void
    {
        $store = $this->managedObjectContext->persistentStoreCoordinator?->persistentStoreForObject($this) ?? fatal_error("Persistent store coordinator cannot be null");
        $managedObjectID = function (EntityDescription $entity, Dictionary $object) use ($store): ?ManagedObjectID {
            $objectID = $object[SQLEntity::primaryKeyName];
            if ($objectID instanceof ManagedObjectID) {
                return $objectID;
            }
            if (!$objectID instanceof Nil) {
                if (($entityName = $object[SQLEntity::entityKeyName]) && !$entityName instanceof Nil && ($entityDescription = $this->managedObjectContext->persistentStoreCoordinator?->managedObjectModel?->entitiesByName[$entityName])) {
                    $entity = $entityDescription;
                }
                if ($objectID) {
                    return $store->objectID($entity, $objectID);
                }
                if (!$object->isEmpty) {
                    return $store->objectID($entity, uuid_generate());
                }
            }
            return null;
        };
        $managedObject = function (EntityDescription $entity, ManagedObject|ManagedObjectID|Dictionary $object) use (&$managedObjectID): ?ManagedObject {
            if ($object instanceof ManagedObject) {
                return $object;
            }
            if ($object instanceof ManagedObjectID) {
                $managedObject = $this->managedObjectContext->object($object);
                $managedObject->awakeFromFetch();
                return $managedObject;
            }
            if ($objectID = $managedObjectID($entity, $object)) {
                $managedObject = $this->managedObjectContext->object($objectID);
                $managedObject->setValuesForKeys($object);
                $managedObject->awakeFromFetch();
                return $managedObject;
            }
            return null;
        };
        $representation = clone $keyedValues;
        if ($representation[SQLEntity::primaryKeyName]) {
            $representation[SQLEntity::primaryKeyName] = $managedObjectID($this->entity, $representation);
        }
        if ($store instanceof SQLCore) {
            /** @var SQLEntity $entity */
            $entity = $store->model->entitiesByName[$this->entity->name];
            foreach ($entity->foreignKeyColumns as $foreignKeyColumn) {
                $key = $foreignKeyColumn->columnName;
                if ($value = $keyedValues[$key]) {
                    /** @noinspection PhpHookedPropertyCantBeAccessedByRefInspection */
                    $representation[$foreignKeyColumn->toOneRelationship->name] = $value instanceof Nil ? $value : (function () use ($value, $foreignKeyColumn): ?ManagedObject {
                        /** @var FetchRequest<ManagedObject> $fetchRequest */
                        $fetchRequest = new FetchRequest();
                        $fetchRequest->entity = $foreignKeyColumn->toOneRelationship->destinationEntity->entityDescription;
                        $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(SQLEntity::primaryKeyName), Expression::expressionForConstantValue((int)$value));
                        /** @noinspection PhpUnhandledExceptionInspection */
                        return $this->managedObjectContext->fetch($fetchRequest)->first;
                    })();
                    $representation->removeValueForKey($key);
                }
            }
        }
        $entity = $this->entity;
        foreach ($keyedValues as $key => $value) {
            $property = $entity->propertiesByName[$key];
            if ($property instanceof RelationshipDescription) {
                $destinationEntity = $property->destinationEntity;
                if ($value instanceof ArrayClass || $value instanceof Set) {
                    if ($property->isToMany) {
                        $representation[$key] = $value->compactMap(fn(ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject => $managedObject($destinationEntity, $object));
                    } elseif (!$value->isEmpty) {
                        fatal_error(sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $entity->name, ArrayClass::class, $key));
                    }
                } elseif ($value instanceof ManagedObject || $value instanceof ManagedObjectID || $value instanceof Dictionary) {
                    $representation[$key] = $managedObject($destinationEntity, $value);
                } elseif ($value instanceof Nil) {
                    $representation[$key] = $value;
                } else {
                    fatal_error(sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $entity->name, typeof($value), $key));
                }
            }
        }
        parent::setValuesForKeys($representation);
    }

    #[Override]
    public function dictionaryWithValues(ArrayClass $keys): Dictionary
    {
        return $keys->reduce(new Dictionary(), function (Dictionary $initial, string $key): Dictionary {
            $value = $this->valueForKey($key);
            if ($this->entity->propertiesByName[$key]?->isSensitive) {
                $value = new SensitivePropertyValue($value);
            }
            /** @psalm-suppress InvalidArgument */
            $initial[$key] = $value;
            return $initial;
        });
    }

    /**
     * Returns the object IDs for all the managed objects that are in the named relationship.
     * @param string $key The name of the relationship.
     * @return ArrayClass<ManagedObjectID> An array of managed object ids.
     * @throws InternalInconsistencyException If key is not a relationship defined by the model, the method raises an exception.
     */
    public function objectIDsForRelationshipNamed(string $key): ArrayClass
    {
        if (!($relationship = $this->entity->relationshipsByName[$key])) {
            fatal_error(sprintf("%s %s() does not contains a relationship named \"%s\"", $this->debugDescription, __FUNCTION__, $key));
        }
        $value = $relationship->isToMany ? $this->mutableSetValueForKey($key) : new Set([$this->primitiveValueForKey($key)]);
        return new ArrayClass($value->map(fn(ManagedObject|ManagedObjectID $e): ManagedObjectID => $e instanceof ManagedObject ? $e->objectID : $e));
    }

    /**
     * @internal
     */
    public static function coercedValue(/** @noinspection PhpUnusedParameterInspection */ mixed $value, AttributeType $type, ?string $attributeValueClassName = null, ?string $valueTransformerName = null, bool $isOptional = true, bool $write = false): mixed
    {
        if ($value instanceof Value) {
            $value = $value->value;
        }
        $coercedValue = fn(string $t): string|int|bool|float|BackedEnum|null => match ($t) {
            "int" => $value instanceof BackedEnum ? $value : (int)$value,
            "bool" => (function () use ($value, $write): bool|int {
                if ($value === null) {
                    $value = false;
                }
                return $write ? new Number($value)->intValue : new Number($value)->boolValue;
            })(),
            "float", => (float)$value,
            default => $value
        };
        $optionalValue = fn(string $t): mixed => match (typeof($value)) {
            "null" => $isOptional ? null : $coercedValue($t),
            default => $coercedValue($t)
        };
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        return match ($type) {
            AttributeType::integer16, AttributeType::integer32, AttributeType::integer64 => $optionalValue("int"),
            AttributeType::decimal, AttributeType::double, AttributeType::float => $optionalValue("float"),
            AttributeType::string => $optionalValue("string"),
            AttributeType::boolean => $optionalValue("bool"),
            AttributeType::date => match (true) {
                $value instanceof Date => $value,
                is_string($value) => $write ? $value : new Date(strtotime($value)),
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
                is_null($value) => $isOptional ? null : fatal_error(sprintf("Invalid argument: attribute type \"%s\" cannot be initialized with a null argument", human_readable_value($type))),
                default => fatal_error(sprintf("Invalid argument: invalid value %s(%s) for type %s", human_readable_value($value), typeof($value), human_readable_value($type)))
            },
            AttributeType::transformable, AttributeType::objectID => (function () use ($value, $write, $valueTransformerName): mixed {
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
                if ($attributeValueClassName !== null) {
                    if ($value && class_exists($attributeValueClassName) && !is_a($value, $attributeValueClassName, true)) {
                        fatal_error(sprintf("Invalid argument: %s %s, expecting \"%s\", \"%s\" given", $property->entity->name, $property->name, $attributeValueClassName, typeof($value)));
                    }
                } elseif (!match ($type) {
                        AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float => is_int($value) || is_float($value) || $value instanceof Number || $value instanceof BackedEnum,
                        AttributeType::string, AttributeType::binaryData => is_string($value) || $value instanceof BackedEnum,
                        AttributeType::boolean => is_bool($value) || is_int($value) || $value instanceof Number,
                        AttributeType::transformable => true,
                        default => false,
                    } && !$property->isOptional) {
                    fatal_error(sprintf("Invalid argument: %s %s, expecting \"%s\", \"%s\" given", $property->entity->name, $property->name, $type->name, typeof($value)));
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
     * Validates a property value for a given key.
     *
     * This method is responsible for two things: coercing the value into an appropriate type for the object, and validating it according to the object's rules.
     * The default implementation provided by ManagedObject consults the object's entity description to coerce the value and to check for basic errors, such as a null value when that isn't allowed and the length of strings when a field width is specified for the attribute.
     * It then searches for a method of the form validate<Key>() and invokes it if it exists.
     * You can implement methods of the form validate<Key>() to perform validation that is not possible using the constraints available in the property description. If it finds an unacceptable value, your validation method should return false and error that describes the problem. For inter-property validation (to check for combinations of values that are invalid), see {@see validateForUpdate()} and related methods.
     * @param mixed|null $value A pointer to an object.
     * @param string $key The name of one of the receiver's properties.
     * @return bool true if value is a valid value for key (or if value can be coerced into a valid value for key), otherwise false. If value is not a valid value for key (and cannot be coerced), the method raises an exception.
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
            if ($key === SQLEntity::primaryKeyName && (is_int($value) || is_string($value))) {
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
                    SQLEntity::entityKeyName => false,
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
        foreach ($this->changedValues as $key => $value) {
            if ($value instanceof Nil) {
                continue;
            }
            /** @var PropertyDescription|null $property */
            $property = $this->entity->propertiesByName[$key];
            if ($property?->isTransient) {
                continue;
            }
            if (!($validationPredicate = $property?->validationPredicates->first(fn(Predicate $predicate) => !$predicate->evaluate($this)))) {
                continue;
            }
            throw new InternalInconsistencyException(error: new Error(CocoaErrorDomain, KeyValueValidationError, new Dictionary([ValidationObjectErrorKey => $this, ValidationValueErrorKey => $value, ValidationKeyErrorKey => $key, ValidationPredicateErrorKey => $validationPredicate])));
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
     * Subclasses should invoke super's implementation before performing their own validation, and should combine any error returned by super's implementation with their own (see Managed Object Validation).
     * @throws Exception If the receiver cannot be inserted in its current state, the method raises an exception.
     */
    public function validateForInsert(): void
    {
        $this->validateChangedValues();
    }

    /**
     * Determines whether the managed object's current state is valid.
     *
     * ManagedObject's implementation iterates through all the receiver's properties validating each in turn. If this results in more than one error, the userInfo dictionary in the Error returned in error contains a key DetailedErrorsKey; the corresponding value is an array containing the individual validation errors. If you pass NULL as the error, validation will abort after the first failure.
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
     * You can invoke this method with the key value of nil to ensure that a fault has been fired, as illustrated by the following example.
     * <code>
     * $managedObject->willAccessValueForKey(null);
     * </code>
     * @param string|null $key The name of one of the receiver's properties.
     */
    public function willAccessValueForKey(?string $key): void
    {
        if ($key === null) {
            try {
                $this->faultHandler->fulfillFault($this);
            } catch (Exception) {
            }
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

    private function serializedObject(ManagedObject $object, RelationshipDescription $relationship): Dictionary
    {
        $serializationRule = $this->serializationRule;
        if ($serializationRule === SerializationRule::attributesAndRelationships) {
            $inverseRelationship = $relationship->inverseRelationship;
            $destinationEntity = $inverseRelationship->destinationEntity;
            if ($this->entity->isKindOf($destinationEntity)) {
                $object->serializationKeys = $object->serializationKeys->filter(fn(string $key): bool => $key !== $inverseRelationship->name);
            }
        } elseif ($serializationRule === SerializationRule::attributesOnly) {
            $object->serializationKeys = $object->entity->attributesByName->keys;
        }
        return $object->jsonSerialize();
    }

    private function serializedRelationshipValueForRelationship(RelationshipDescription $relationship): Set|Dictionary|null
    {
        $key = $relationship->name;
        $value = $this->valueForKey($key);
        if ($value instanceof ManagedObject) {
            return $this->serializedObject($value, $relationship);
        }
        if ($value instanceof Set) {
            return $value->map(fn(ManagedObject $object): Dictionary => $this->serializedObject($object, $relationship));
        }
        if ($relationship->isToMany && !$relationship->isOptional) {
            return new Set();
        }
        return null;
    }

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        return $this->serializationKeys->reduce(new Dictionary(), function (Dictionary &$dictionary, string $key): Dictionary {
            if ($property = $this->entity->propertiesByName[$key]) {
                if ($property instanceof AttributeDescription) {
                    $value = $this->valueForKey($key) ?? Nil::nil();
                    if ($property->isSensitive) {
                        $value = new SensitivePropertyValue($value);
                    }
                    /** @psalm-suppress InvalidArgument */
                    $dictionary[$key] = $value;
                } elseif ($property instanceof RelationshipDescription) {
                    if (!($value = $this->serializedRelationshipValueForRelationship($property))) {
                        /** @noinspection PhpVoidFunctionResultUsedInspection */
                        $value = $property->isOptional ? Nil::nil() : ($property->isToMany ? new Set() : fatal_error(sprintf("%s property \"%s\" is not optional", $this->debugDescription, $property->name)));
                    }
                    /** @psalm-suppress InvalidArgument */
                    $dictionary[$key] = $value;
                } else {
                    $dictionary[$key] = $this->valueForKey($key);
                }
            } else {
                /** @psalm-suppress InvalidArgument */
                $dictionary[$key] = $this->valueForKey($key) ?? Nil::nil();
            }
            return $dictionary;
        });
    }

    /**
     * @psalm-suppress LessSpecificReturnStatement, MoreSpecificReturnType
     */
    public function serialized(?Dictionary $serialization = null): static
    {
        return ManagedObjectSerializer::shared()->serialized($this, $serialization);
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
