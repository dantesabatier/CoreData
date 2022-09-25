<?php /** @noinspection PhpUnused */

namespace Sabatier\CoreData;

use DateTime;
use Exception;
use InvalidArgumentException;
use JetBrains\PhpStorm\ExpectedValues;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonPredicate;
use Sabatier\Foundation\ComparisonResult;
use Sabatier\Foundation\CompoundPredicate;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\Expression;
use Sabatier\Foundation\ExpressionType;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\KeyValueChange;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\Value;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\is_serialized;
use function Sabatier\Foundation\typeof;
use const Sabatier\Foundation\KeyValueValidationError;

/**
 * Class ManagedObject
 * A base class that implements the behavior required of a Core Data model object.
 * @package Sabatier\CoreData
 * @property-read bool $isInserted A Boolean value that indicates whether the managed object has been inserted in a managed object context.
 * @property-read bool $isUpdated A Boolean value that indicates whether the managed object has unsaved changes.
 * @property-read bool $isDeleted A Boolean value that indicates whether the managed object will be deleted during the next save.
 * @property-read bool $hasChanges A Boolean value that indicates whether the managed object has been inserted, has been deleted, or has unsaved changes.
 * @property-read bool $hasPersistentChangedValues A Boolean value that indicates whether the managed object has persistent changes.
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
    /** @var ManagedObjectContext The managed object context with which the managed object is registered. May be nil if the receiver has been deleted from its context. If the receiver is a fault, accessing this property does not cause it to fire. */
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
    public bool $isUnprocessedUpdate = false;
    /** @internal */
    public bool $isUnprocessedInsertion = false;
    /** @internal */
    public bool $isUnprocessedDeletion = false;
    /** @internal */
    public bool $isPendingUpdate = false;
    /** @internal */
    public bool $isPendingInsertion = false;
    /** @internal */
    public bool $isPendingDeletion = false;
    /** @internal */
    public bool $isSuppressingKVO = false;
    /** @internal */
    public bool $isSuppressingChangeNotifications = false;

    /**
     * Initializes a managed object from an entity description and inserts it into the specified managed object context.
     * @param ManagedObjectContext $managedObjectContext The context into which the new instance is inserted.
     * @param EntityDescription|null $entity The entity of which to create an instance.
     * The model associated with context's persistent store coordinator must contain entity.
     * If the receiver is a fault, accessing this property does not cause it to fire.
     */
    public function __construct(ManagedObjectContext $managedObjectContext, ?EntityDescription $entity = null)
    {
        if ($this->isSubclass(ManagedObject::class)) {
            $entity ??= static::entity();
        }
        unset($this->serializationKeys);
        unset($this->faultHandler);
        unset($this->allProperties);
        unset($this->modeledProperties);
        unset($this->persistentProperties);
        unset($this->transientProperties);
        unset($this->changedValues);
        unset($this->changedValuesForCurrentEvent);
        unset($this->objectID);
        $this->entity = $entity ?? throw new InvalidArgumentException("invalid argument: entity cannot be null");
        $this->managedObjectContext = $managedObjectContext;
        $this->managedObjectContext->insert($this);
    }

    public function __get(string $name)
    {
        if ($name == 'objectID') {
            $this->$name = new ManagedObjectID($this->entity, (new UUID())->uuidString);
            return $this->$name;
        } elseif ($name == 'changedValues') {
            $this->$name = new Dictionary();
            return $this->$name;
        } elseif ($name == 'changedValuesForCurrentEvent') {
            $this->$name = new Dictionary();
            return $this->$name;
        } elseif ($name == 'faultHandler') {
            $this->$name = ($this->managedObjectContext->persistentStoreCoordinator?->persistentStoreForObject($this) ?? throw new InternalInconsistencyException())->faultHandler;
            return $this->$name;
        } elseif ($name == 'allProperties') {
            $this->$name = $this->entity->properties;
            return $this->$name;
        } elseif ($name == 'modeledProperties') {
            $this->$name = $this->allProperties;
            return $this->$name;
        } elseif ($name == 'persistentProperties') {
            $this->$name = $this->allProperties->filter(fn(PropertyDescription $property): bool => !$property->isTransient && !$property instanceof FetchedPropertyDescription);
            return $this->$name;
        } elseif ($name == 'transientProperties') {
            $this->$name = $this->modeledProperties->filter(fn(PropertyDescription $property): bool => $property->isTransient);
            return $this->$name;
        } elseif ($name == 'serializationKeys') {
            /** @psalm-suppress InvalidArgument */
            $this->$name = match ($this->serializationRule) {
                SerializationRule::attributesOnly => $this->entity->attributesByName->filter(fn(AttributeDescription $attribute, string $key): bool => !$attribute->isTransient && !$this->isRelationshipForKeyFault($key))->keys,
                SerializationRule::attributesAndRelationships => $this->entity->attributesByName->filter(fn(AttributeDescription $attribute, string $key): bool => !$attribute->isTransient && !$this->isRelationshipForKeyFault($key))->merging($this->entity->relationshipsByName)->keys,
                default => new ArrayClass(),
            };
            return $this->$name;
        } elseif ($name == 'hasPersistentChangedValues') {
            return !$this->changedValues()->isEmpty();
        } elseif ($name == 'hasChanges') {
            return $this->isInserted || $this->isUpdated || $this->isDeleted;
        } elseif ($name == 'isInserted') {
            return $this->managedObjectContext->insertedObjects->containsElement($this);
        } elseif ($name == 'isUpdated') {
            return $this->managedObjectContext->updatedObjects->containsElement($this);
        } elseif ($name == 'isDeleted') {
            return $this->managedObjectContext->deletedObjects->containsElement($this);
        } else {
            return $this->valueForKey($name);
        }
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name == 'changedValues' || $name == 'changedValuesForCurrentEvent' || $name == 'serializationKeys' || $name == 'allProperties' || $name == 'modeledProperties' || $name == 'persistentProperties' || $name == 'transientProperties' || $name == 'faultHandler') {
            $this->$name = $value;
        } else {
            $this->setValueForKey($value, $name);
        }
    }

    public function __call(string $name, array $arguments)
    {
        if ($method = $this->faultingMutableSetMutationMethods?->first(fn(FaultingMutableSetMutationMethod $method): bool => $method->name === $name)) {
            ($method->closure)(...$arguments);
            return;
        }
        $this->doesNotRecognizeSelector($name);
    }

    public function responds(string $selector): bool
    {
        return parent::responds($selector) || $this->faultingMutableSetMutationMethods?->contains(fn(FaultingMutableSetMutationMethod $method): bool => $method->name === $selector) || $this->entity->propertiesByName->contains(fn(PropertyDescription $property): bool => $property->name === $selector);
    }

    public function performSelector(string $selector, array $arguments = []): mixed
    {
        if ($this->faultingMutableSetMutationMethods?->contains(fn(FaultingMutableSetMutationMethod $method): bool => $method->name === $selector)) {
            return $this->$selector(...$arguments);
        } elseif ($this->entity->propertiesByName->contains(fn(PropertyDescription $property): bool => $property->name === $selector)) {
            return $this->valueForKey($selector);
        } else {
            return parent::performSelector($selector, $arguments);
        }
    }

    /**
     * Returns the entity description that is associated with this subclass.
     * This method is only legal to call on subclasses of ManagedObject that represent a single entity in the model.
     * @return EntityDescription
     */
    public static function entity(): EntityDescription
    {
        return static::staticAssociatedValueForKey(__FUNCTION__);
    }

    /**
     * Returns a Boolean value that indicates whether the relationship for a given key is a fault.
     * If the specified relationship is a fault, calling this method does not result in the fault firing.
     * @param string $key The name of one of the receiver's relationships.
     * @return bool true if the relationship for the key is a fault, otherwise false.
     */
    public function hasFaultForRelationshipNamed(string $key): bool
    {
        if ($this->entity->relationshipsByName[$key]) {
            return $this->isRelationshipForKeyFault($key);
        }
        throw new InternalInconsistencyException(sprintf("this class does not contains a relationship named \"%s\"", $key));
    }

    /**
     * @internal
     */
    public function isRelationshipForKeyFault(string $key): bool
    {
        if ($this->isSuppressingKVO || $this->isSuppressingChangeNotifications) {
            return true;
        }
        $value = $this->primitiveValueForKey($key);
        $property = $this->entity->propertiesByName[$key];
        if ($property instanceof AttributeDescription) {
            if ($property instanceof DerivedAttributeDescription) {
                return true;
            }
            return false;
        } elseif ($property instanceof FetchedPropertyDescription) {
            if ($value instanceof FaultingMutableArray) {
                return $value->isFault;
            }
            return true;
        } elseif ($property instanceof RelationshipDescription) {
            if ($property->isToMany) {
                if ($value instanceof FaultingMutableSet) {
                    return $value->isFault;
                }
                return true;
            }
            return $value === null;
        }
        return true;
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object when fufilling it from a fault.
     * You typically use this method to compute derived values or to recreate transient relationships from the receiver's persistent properties.
     * The managed object context's change processing is explicitly disabled around this method so that you can use public setters to establish transient values and other caches without dirtying the object or its context.
     * Because of this, however, you should not modify relationships in this method as the inverse will not be set.
     * Subclasses must invoke super's implementation before performing their own initialization.
     */
    public function awakeFromFetch(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object when initially creating it.
     * You typically use this method to initialize special default property values.
     * This method is invoked only once in the object's lifetime.
     * If you want to set attribute values in an implementation of this method, you should typically use primitive accessor methods (either {@see setPrimitiveValueForKey()} or better the appropriate custom primitive accessors).
     * This ensures that the new values are treated as baseline values rather than being recorded as undoable changes for the properties in question.
     * Subclasses must invoke super's implementation before performing their own initialization.
     */
    public function awakeFromInsert(): void
    {
        $isSuppressingKVO = $this->isSuppressingKVO;
        $this->isSuppressingKVO = true;
        /** @var Dictionary<mixed> $dictionary */
        $dictionary = new Dictionary();
        /** @var PropertyDescription $property */
        foreach ($this->modeledProperties as $property) {
            $value = null;
            $key = $property->name;
            if ($property instanceof AttributeDescription) {
                /** @var scalar|null $value */
                $value = $property->defaultValue;
                if (($value === null) && !$property->isOptional) {
                    $attributeType = $property->type;
                    switch ($attributeType) {
                        case AttributeType::integer16:
                        case AttributeType::integer32:
                        case AttributeType::integer64:
                        case AttributeType::decimal:
                        case AttributeType::double:
                        case AttributeType::float:
                        case AttributeType::boolean:
                        case AttributeType::date:
                            $value = self::coercedValue($value, $attributeType);
                            break;
                        default:
                            break;
                    }
                }
            } elseif ($property instanceof FetchedPropertyDescription) {
                $value = new FaultingMutableArray($this, $property);
            } elseif ($property instanceof RelationshipDescription) {
                if ($property->isToMany) {
                    $value = new FaultingMutableSet($this, $property);
                    $this->createMutationMethods($key);
                }
            }
            $dictionary[$key] = $value;
        }
        $this->setValuesForKeys($dictionary);
        $this->isSuppressingKVO = $isSuppressingKVO;
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object when fulfilling it from a snapshot.
     * You typically use this method to compute derived values or to recreate transient relationships from the receiver's persistent properties.
     * If you want to set attribute values and need to avoid emitting key-value observation change notifications, you should use primitive accessor methods (either {@see setPrimitiveValue()} or better the appropriate custom primitive accessors). This ensures that the new values are treated as baseline values rather than being recorded as undoable changes for the properties in question.
     * Subclasses must invoke super's implementation before performing their own initialization.
     * @param int $flags A bit mask of {@see SnapshotEventType} constants to denote the event or events that led to the method being invoked.
     * For possible values, see {@see SnapshotEventType}.
     */
    public function awakeFromSnapshotEvents(#[ExpectedValues(flagsFromClass: SnapshotEventType::class)] int $flags): void
    {
    }

    /**
     * Returns a dictionary containing the keys and new values of persistent properties with changes since the last fetching or saving of the managed object.
     * This method only reports changes to properties that are persistent properties of the receiver, not changes to transient properties or custom instance variables.
     * @return Dictionary A dictionary with keys that are the names of persistent properties with changes since last fetching or saving the receiver, and with the new values for those properties.
     */
    public function changedValues(): Dictionary
    {
        /** @var ArrayClass<string> $keys */
        $keys = $this->persistentProperties->valueForKey('name');
        return $this->changedValues->filter(fn(mixed $value, string $key): bool => $keys->containsElement($key));
    }

    /**
     * Returns a dictionary containing the keys and new values of persistent properties with changes since the last fetching or saving of the managed object.
     * This method only reports changes to properties that are persistent properties of the receiver, not changes to transient properties or custom instance variables.
     * @return Dictionary A dictionary with keys that are the names of persistent properties with changes since the last posting of {@see ManagedObjectContextObjectsDidChange}, and with the new values for those properties.
     */
    public function changedValuesForCurrentEvent(): Dictionary
    {
        return $this->changedValuesForCurrentEvent;
    }

    /**
     * Returns a dictionary of the most recent fetched or saved values of the managed object for the properties of the specified keys.
     * nil values are represented by {@see Nil}.
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
        $values = $this->changedValuesForCurrentEvent();
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
     * This method is the companion of the {@see didTurnIntoFault()} method. You can use it to (re)set state which requires access to property values (for example, observers across key paths).
     * The default implementation does nothing.
     */
    public function willTurnIntoFault(): void
    {
    }

    /**
     * Provides an opportunity to add code into the life cycle of the managed object after converting it to a fault.
     * You use this method to clear out custom data caches transient values declared as entity properties are typically already cleared out by the time this method is invoked (see, for example, {@see ManagedObjectContext::refresh()}).
     */
    public function didTurnIntoFault(): void
    {
    }

    /**
     * Returns an initialized fetch request with the entity this subclass represents.
     * This method is only legal to call on subclasses of ManagedObject that represent a single entity in the model.
     * @return FetchRequest
     */
    public static function fetchRequest(): FetchRequest
    {
        $fetchRequest = new FetchRequest();
        $fetchRequest->entity = self::entity();
        return $fetchRequest;
    }

    public function mutableSetValueForKey(string $key): Set
    {
        if (!($relationship = $this->entity->relationshipsByName[$key])) {
            return $this->valueForUndefinedKey($key);
        }
        if (!$relationship->isToMany) {
            throw new InvalidArgumentException(sprintf('%s does not contains a to many relationship named "%s"', $this->debugDescription(), $key));
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

    public function mutableArrayValueForKey(string $key): ArrayClass
    {
        if (!($property = $this->entity->propertiesByName[$key])) {
            return $this->valueForUndefinedKey($key);
        }
        $mutableArray = $this->primitiveValueForKey($key);
        if (!$mutableArray instanceof FaultingMutableArray) {
            $array = new FaultingMutableArray($this, $property);
            if ($mutableArray instanceof ArrayClass) {
                $array->appendContentsOf($mutableArray);
            }
            $this->setPrimitiveValueForKey($array, $key);
            $mutableArray = $array;
        }
        return $mutableArray;
    }

    /**
     * Returns the value for the specified property from the managed object's private internal storage.
     * This method does not invoke the access notification methods ({@see willAccessValueForKey()} and {@see didAccessValueForKey()}).
     * This method is used primarily by subclasses that implement custom accessor methods that need direct access to the receiver's private storage.
     * Subclasses should not override this method.
     * @param string $key The name of one of the receiver's properties.
     * @return mixed The value of the property specified by key. Returns nil if no value has been set.
     */
    public function primitiveValueForKey(string $key): mixed
    {
        return $this->changedValues[$key];
    }

    /**
     * Sets the value of a given property in the managed object's private internal storage.
     * Sets in the receiver's private internal storage the value of the property specified by key to value.
     * If key identifies a to-one relationship, relates the object specified by value to the receiver, unrelating the previously related object if there was one. Given a collection object and a key that identifies a to-many relationship, relates the objects contained in the collection to the receiver, unrelating previously related objects if there were any.
     * This method does not invoke the change notification methods ({@see willChangeValueForKey()} and {@see didChangeValueForKey()}).
     * It is typically used by subclasses that implement custom accessor methods that need direct access to the receiver's private internal storage. It is also used by the Core Data framework to initialize the receiver with values from a persistent store or to restore a value from a snapshot.
     * You must not override this method.
     * @param mixed|null $value The new value for the property specified by key.
     * @param string $key The name of one of the receiver's properties.
     */
    public function setPrimitiveValueForKey(mixed $value, string $key): void
    {
        $this->changedValues[$key] = $value;
    }

    /**
     * Returns the value for the property specified by key.
     * If key is not a property defined by the model, the method raises an exception.
     * This method is overridden by ManagedObject to access the managed object's generic dictionary storage unless the receiver's class explicitly provides key-value coding compliant accessor methods for key.
     * You must not override this method.
     * @param string $key The name of one of the receiver's properties.
     * @return mixed The value of the property specified by key.
     * @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection
     */
    public function valueForKey(string $key): mixed
    {
        if (empty($key)) {
            return $this->valueForUndefinedKey($key);
        }
        $entity = $this->entity;
        $context = $this->managedObjectContext;
        $property = $entity->propertiesByName[$key];
        if ($property instanceof AttributeDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if ($property instanceof DerivedAttributeDescription) {
                if (!$value && !isset($this->reserved[$key]) && $this->isRelationshipForKeyFault($key)) {
                    $this->reserved[$key] = true;
                    $value = $property->derivationExpression?->expressionValue($this);
                    unset($this->reserved[$key]);
                }
            }
            return $value;
        } elseif ($property instanceof FetchedPropertyDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if (!isset($this->reserved[$key]) && $this->isRelationshipForKeyFault($key)) {
                $this->reserved[$key] = true;
                $value ??= new FaultingMutableArray($this, $property);
                if ($fetchRequest = $property->fetchRequest) {
                    if ($entityName = $fetchRequest->entityName) {
                        $fetchRequest->entity = EntityDescription::entity($entityName, $context);
                    }
                    if ($predicate = $fetchRequest->predicate) {
                        $fn = function (CompoundPredicate|ComparisonPredicate $predicate) use (&$fn, $property): CompoundPredicate|ComparisonPredicate {
                            if ($predicate instanceof ComparisonPredicate) {
                                $expression = function (Expression $expression) use ($property): Expression {
                                    if (($expression->expressionType == ExpressionType::keyPath) && $expression->operand()?->expressionType == ExpressionType::variable) {
                                        $expression = Expression::expressionForConstantValue($expression->expressionValue($this, new Dictionary(["\$FETCH_SOURCE" => $this, "\$FETCHED_PROPERTY" => $property])));
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
                unset($this->reserved[$key]);
            }
            return $value;
        } elseif ($property instanceof RelationshipDescription) {
            $this->willAccessValueForKey($key);
            $value = $this->primitiveValueForKey($key);
            $this->didAccessValueForKey($key);
            if (!isset($this->reserved[$key]) && $this->isRelationshipForKeyFault($key)) {
                $this->reserved[$key] = true;
                $store = $context->persistentStoreCoordinator?->persistentStoreForObject($this) ?? throw new InternalInconsistencyException();
                $newValue = $store->newValueForRelationship($property, $this->objectID, $context);
                if ($property->isToMany) {
                    $value ??= new FaultingMutableSet($this, $property);
                    $value->setSet(new Set($newValue));
                } else {
                    $value = $newValue;
                }
                $this->setPrimitiveValueForKey($value, $key);
                unset($this->reserved[$key]);
            }
            if ($value instanceof FaultingMutableArray || $value instanceof FaultingMutableSet || $value instanceof ManagedObject) {
                return $value;
            } elseif ($value instanceof ManagedObjectID) {
                return $context->object($value);
            } else {
                if ($property->isToMany) {
                    return new Set();
                }
                return null;
            }
        } else {
            return parent::valueForKey($key);
        }
    }

    /**
     * Sets the specified property of the managed object to the specified value.
     * If key is not a property defined by the model or if is not part of the receiver's properties, the method raises an exception. If key identifies a to-one relationship, relates the object specified by value to the receiver, unrelating the previously related object if there was one. Given a collection object and a key that identifies a to-many relationship, relates the objects contained in the collection to the receiver, unrelating previously related objects if there were any.
     * This method is overridden by ManagedObject to access the managed object's generic dictionary storage unless the receiver's class explicitly provides key-value coding compliant accessor methods for key.
     * You must not override this method.
     * @param mixed|null $value The new value for the property specified by key.
     * @param string $key The name of one of the receiver's properties.
     */
    public function setValueForKey(mixed $value, string $key): void
    {
        if (!$this->validateValueForKey($value, $key)) {
            return;
        }
        $entity = $this->entity;
        $property = $entity->propertiesByName[$key];
        if ($property && !$property instanceof DerivedAttributeDescription && !$property instanceof FetchedPropertyDescription && !$this->isSuppressingKVO && !$this->isSuppressingChangeNotifications) {
            $this->changedValuesForCurrentEvent[$key] = $value ?? Nil::nil();
        }
        if ($property instanceof AttributeDescription) {
            $change = $value;
            $current = $this->primitiveValueForKey($key);
            $this->willChangeValueForKey($key, KeyValueChange::replacement, $current);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key, KeyValueChange::replacement, $change);
        } elseif ($property instanceof FetchedPropertyDescription) {
            $this->willChangeValueForKey($key);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key);
        } elseif ($property instanceof RelationshipDescription) {
            $inverseRelationship = $property->inverseRelationship;
            if ($property->isToMany) {
                assert($value instanceof Set, sprintf("invalid parameter: expecting \"%s\", \"%s\" given", Set::class, typeof($value)));
                $set = new FaultingMutableSet($this, $property);
                $set->setSet($value);
                $value = $set;
                $change = $this->mutableSetValueForKey($key);
                if (!$this->isSuppressingKVO && !$this->isSuppressingChangeNotifications && !$this->objectID->isTemporaryID && $this->isRelationshipForKeyFault($key)) {
                    /** @var FaultingMutableSet $change */
                    $change = $this->valueForKey($key);
                    /** @var ManagedObject $object */
                    foreach ($change as $object) {
                        if ($member = $value->member($object)) {
                            $object->setValuesForKeys($member->dictionaryWithValues($member->entity->attributesByName->keys));
                        }
                    }
                }
                $comparisonResult = $change->compare($value);
                if ($comparisonResult == ComparisonResult::orderedDescending) {
                    $change->subtract($value);
                    $changeKind = KeyValueChange::removal;
                } elseif ($comparisonResult == ComparisonResult::orderedAscending) {
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
            } else {
                $change = $value;
                $current = $this->primitiveValueForKey($key);
                if (!$this->isSuppressingKVO && !$this->isSuppressingChangeNotifications && !$this->objectID->isTemporaryID && !$entity->isKindOf($inverseRelationship->destinationEntity) && $this->isRelationshipForKeyFault($key)) {
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
            }
            $this->willChangeValueForKey($key, $changeKind, $change);
            $this->setPrimitiveValueForKey($value, $key);
            $this->didChangeValueForKey($key, $changeKind, $change);
        } else {
            parent::setValueForKey($value, $key);
        }
    }

    public function setValuesForKeys(Dictionary $keyedValues): void
    {
        $managedObjectID = function (EntityDescription $entity, mixed $object): ?ManagedObjectID {
            $objectID = $object['objectID'];
            if ($objectID instanceof ManagedObjectID) {
                return $objectID;
            }
            $newObjectID = function (EntityDescription $entity, int|string $referenceObject): ManagedObjectID {
                $store = $this->managedObjectContext->persistentStoreCoordinator?->persistentStoreForObject($this) ?? throw new InternalInconsistencyException();
                if ($store instanceof AtomicStore) {
                    return $store->objectID($entity, $referenceObject);
                } elseif ($store instanceof IncrementalStore) {
                    return $store->newObjectID($entity, $referenceObject);
                }
                throw new InvalidArgumentException();
            };
            if ($objectID) {
                if (($entityName = $object['entityName']) && ($entityDescription = $this->managedObjectContext->persistentStoreCoordinator?->managedObjectModel?->entitiesByName[$entityName])) {
                    $entity = $entityDescription;
                }
                return $newObjectID($entity, $objectID);
            }
            if (!$object->isEmpty()) {
                return $newObjectID($entity, (new UUID())->uuidString);
            }
            return null;
        };
        $managedObject = function (EntityDescription $entity, ManagedObject|ManagedObjectID|Dictionary $object) use (&$managedObjectID): ?ManagedObject {
            if ($object instanceof ManagedObject) {
                return $object;
            } elseif ($object instanceof ManagedObjectID) {
                return $this->managedObjectContext->object($object);
            } elseif ($objectID = $managedObjectID($entity, $object)) {
                $managedObject = $this->managedObjectContext->object($objectID);
                $managedObject->setValuesForKeys($object);
                return $managedObject;
            }
            return null;
        };
        $representation = clone $keyedValues;
        if ($representation['objectID']) {
            $objectID = $managedObjectID($this->entity, $representation);
            if ($objectID) {
                $representation->setValueForKey($objectID, 'objectID');
            } else {
                $representation->removeValueForKey('objectID');
            }
        }
        foreach ($this->entity as $property) {
            $name = $property->name;
            if ($property instanceof RelationshipDescription) {
                $value = $representation[$name];
                if ($value) {
                    $destinationEntity = $property->destinationEntity;
                    if ($value instanceof Set || $value instanceof ArrayClass) {
                        $representation->setValueForKey($value->compactMap(fn(ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject => $managedObject($destinationEntity, $object)), $name);
                    } elseif ($value instanceof ManagedObject || $value instanceof ManagedObjectID || $value instanceof Dictionary) {
                        $object = $managedObject($destinationEntity, $value);
                        if ($object) {
                            $representation->setValueForKey($object, $name);
                        } else {
                            $representation->removeValueForKey($name);
                        }
                    } elseif ($value instanceof Nil) {
                        $representation->setValueForKey($value, $name);
                    } else {
                        throw new InvalidArgumentException(sprintf("attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", typeof($value), $name));
                    }
                }
            }
        }
        parent::setValuesForKeys($representation);
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
            throw new InternalInconsistencyException(sprintf('%s %s() does not contains a relationship named "%s"', $this->debugDescription(), __FUNCTION__, $key));
        }
        $value = $relationship->isToMany ? $this->mutableSetValueForKey($key) : new Set($this->primitiveValueForKey($key));
        return new ArrayClass($value->map(function (mixed $obj): ManagedObjectID {
            if ($obj instanceof ManagedObject) {
                return $obj->objectID;
            }
            assert($obj instanceof ManagedObjectID, sprintf("invalid parameter: expecting \"%s\", given \"%s\"", ManagedObjectID::class, typeof($obj)));
            return $obj;
        }));
    }

    /**
     * @param mixed $value
     * @param AttributeType $attributeType
     * @param bool $in
     * @return mixed
     * @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection
     * @internal
     */
    public static function coercedValue(mixed $value, AttributeType $attributeType, bool $in = false): mixed
    {
        if ($value instanceof Value) {
            $value = $value->value;
        }
        switch ($attributeType) {
            case AttributeType::undefined:
            case AttributeType::objectID:
            case AttributeType::binaryData:
            case AttributeType::string:
                if ($value !== null) {
                    $value = (string)$value;
                }
                break;
            case AttributeType::integer16:
            case AttributeType::integer32:
            case AttributeType::integer64:
                $value = (int)$value;
                break;
            case AttributeType::decimal:
            case AttributeType::double:
                $value = (double)$value;
                break;
            case AttributeType::float:
                $value = (float)$value;
                break;
            case AttributeType::boolean:
                $value = $in ? (int)$value : (bool)$value;
                break;
            case AttributeType::date:
                if ($in) {
                    $value instanceof Date ? $value->formatted() : ($value ?? (new Date())->formatted());
                } else {
                    $value = $value instanceof Date ? $value : new Date((new DateTime($value ?? 'now'))->getTimestamp());
                }
                break;
            case AttributeType::uuid:
                if ($in) {
                    $value = $value instanceof UUID ? $value : new UUID($value);
                } else {
                    $value = $value instanceof UUID ? $value : ($value ? new UUID($value) : null);
                }
                break;
            case AttributeType::uri:
                $value = $value instanceof URL ? $value : ($value ? new URL($value) : null);
                break;
            case AttributeType::transformable:
                if ($value && ($transformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName))) {
                    $value = $in ? $transformer->transformedValue($value) : $transformer->reverseTransformedValue($value);
                }
                break;
        }
        return $value;
    }

    /**
     * @param mixed|null $value
     * @param PropertyDescription $property
     * @param bool $in
     * @return bool
     * @internal
     */
    public static function coerceValue(mixed &$value, PropertyDescription $property, bool $in = false): bool
    {
        if ($value instanceof Nil) {
            $value = $value->value;
        }
        if ($property instanceof AttributeDescription) {
            $attributeType = $property->type;
            if ($value === null) {
                if (!$property->isOptional) {
                    $value = $property->defaultValue ?? self::coercedValue($value, $attributeType, $in);
                    $value = self::coercedValue($value, $attributeType, $in);
                }
            } else {
                if ($attributeType == AttributeType::transformable) {
                    if (($attributeValueClassName = $property->attributeValueClassName) && !is_a($value, $attributeValueClassName, true)) {
                        if (is_string($value) && !is_serialized($value)) {
                            $value = self::coercedValue($value, $attributeType, $in);
                        }
                    }
                    $transformerName = $property->valueTransformerName ?? SecureUnarchiveFromDataTransformerName;
                    if ($transformer = ValueTransformer::valueTransformerForName($transformerName)) {
                        $value = $in ? $transformer->transformedValue($value) : $transformer->reverseTransformedValue($value);
                    }
                } else {
                    $value = self::coercedValue($value, $attributeType, $in);
                    if ($attributeValueClassName = $property->attributeValueClassName) {
                        if ($value && !is_a($value, $attributeValueClassName, true)) {
                            throw new InvalidArgumentException(sprintf("invalid parameter: %s %s, expecting \"%s\", \"%s\" given", $property->entity->name, $property->name, $attributeValueClassName, typeof($value)));
                        }
                    } else {
                        /** @noinspection PhpConditionAlreadyCheckedInspection */
                        if (!match ($attributeType) {
                            AttributeType::integer16, AttributeType::integer32, AttributeType::integer64, AttributeType::decimal, AttributeType::double, AttributeType::float => is_numeric($value),
                            AttributeType::string, AttributeType::binaryData, AttributeType::transformable => is_string($value),
                            AttributeType::boolean => is_bool($value) || is_int($value),
                            default => false,
                        }) {
                            throw new InvalidArgumentException(sprintf("invalid parameter: %s %s, expecting \"%s\", \"%s\" given", $property->entity->name, $property->name, $attributeType->name, typeof($value)));
                        }
                    }
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
     * This method is responsible for two things: coercing the value into an appropriate type for the object, and validating it according to the object's rules.
     * The default implementation provided by ManagedObject consults the object's entity description to coerce the value and to check for basic errors, such as a null value when that isn't allowed and the length of strings when a field width is specified for the attribute.
     * It then searches for a method of the form validate<Key>() and invokes it if it exists.
     * You can implement methods of the form validate<Key>() to perform validation that is not possible using the constraints available in the property description. If it finds an unacceptable value, your validation method should return false and error that describes the problem. For inter-property validation (to check for combinations of values that are invalid), see {@see validateForUpdate()} and related methods.
     * @param mixed|null $value A pointer to an object.
     * @param string $key The name of one of the receiver's properties.
     * @return bool true if value is a valid value for key (or if value can be coerced into a valid value for key), otherwise false. If value is not a valid value for key (and cannot be coerced), the method raises an exception.
     */
    public function validateValueForKey(mixed &$value, string $key): bool
    {
        if (parent::validateValueForKey($value, $key)) {
            if ($property = $this->entity->propertiesByName[$key]) {
                return self::coerceValue($value, $property);
            } elseif (property_exists($this, $key)) {
                if ($key == 'objectID') {
                    if (is_int($value) || is_string($value)) {
                        $this->objectID->referenceObject = $value;
                        return false;
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * @throws Exception
     */
    private function validateProperties(): void
    {
        foreach ($this->changedValues as $key => $value) {
            /** @var PropertyDescription $property */
            $property = $this->entity->propertiesByName[$key];
            foreach ($property->validationPredicates as $validationPredicate) {
                if ($value !== null && !$validationPredicate->evaluate($this)) {
                    throw new Exception((new Error(CocoaErrorDomain, KeyValueValidationError, new Dictionary([ValidationObjectErrorKey => $this, ValidationValueErrorKey => $value, ValidationKeyErrorKey => $key, ValidationPredicateErrorKey => $validationPredicate])))->description());
                }
            }
        }
    }

    /**
     * Determines whether the managed object can be deleted in its current state.
     * An object cannot be deleted if it has a relationship has a “deny” delete rule and that relationship has a destination object.
     * ManagedObject's implementation sends the receiver's entity description a message which performs basic checking based on the presence or absence of values.
     * @throws Exception If the receiver cannot be deleted in its current state, the method raises an exception.
     */
    public function validateForDelete(): void
    {
    }

    /**
     * Determines whether the managed object can be inserted in its current state.
     * Subclasses should invoke super's implementation before performing their own validation, and should combine any error returned by super's implementation with their own (see Managed Object Validation).
     * @throws Exception If the receiver cannot be inserted in its current state, the method raises an exception.
     */
    public function validateForInsert(): void
    {
        $this->validateProperties();
    }

    /**
     * Determines whether the managed object's current state is valid.
     * ManagedObject's implementation iterates through all the receiver's properties validating each in turn. If this results in more than one error, the userInfo dictionary in the Error returned in error contains a key DetailedErrorsKey; the corresponding value is an array containing the individual validation errors. If you pass NULL as the error, validation will abort after the first failure.
     * @throws Exception If the receiver's current state is invalid, the method raises an exception.
     */
    public function validateForUpdate(): void
    {
        $this->validateProperties();
    }

    /**
     * Provides support for key-value observing access notification.
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
            } catch (Exception $exception) {
                trigger_error($exception->getMessage(), E_USER_WARNING);
            }
        }
    }

    public function willChangeValueForKey(string $key, KeyValueChange $changeKind = KeyValueChange::setting, mixed $changedValue = null): void
    {
        if (!$this->isSuppressingKVO) {
            parent::willChangeValueForKey($key, $changeKind, $changedValue);
        }
    }

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
        if (!$this->isRelationshipForKeyFault($key)) {
            $value = $this->valueForKey($key);
            if ($value instanceof ManagedObject) {
                return $this->serializedObject($value, $relationship);
            } elseif ($value instanceof Set) {
                return $value->map(fn(ManagedObject $object): Dictionary => $this->serializedObject($object, $relationship));
            }
        }
        if ($relationship->isToMany) {
            return new Set();
        }
        return null;
    }

    public function jsonSerialize(): Dictionary
    {
        $dictionary = new Dictionary(['objectID' => $this->objectID->referenceObject]);
        $dictionary->merge($this->serializationKeys->reduce(new Dictionary(), function (Dictionary &$result, string $key): Dictionary {
            if ($property = $this->entity->propertiesByName[$key]) {
                if ($property instanceof AttributeDescription) {
                    $result[$key] = $this->valueForKey($key);
                } elseif ($property instanceof RelationshipDescription) {
                    $result[$key] = $this->serializedRelationshipValueForRelationship($property);
                } else {
                    $result[$key] = $this->valueForKey($key);
                }
            }
            return $result;
        }));
        return $dictionary;
    }

    public function serialized(?Dictionary $serialization = null): self
    {
        return ManagedObjectSerializer::serialized($this, $serialization);
    }

    final public function isEqual(mixed $other): bool
    {
        if ($other instanceof ManagedObject) {
            if ($this->entity->isKindOf($other->entity)) {
                return $this->objectID->isEqual($other->objectID);
            }
        }
        return false;
    }

    public function description(): string
    {
        return sprintf('<%s %s> (entity: %s; id: %s %s; data: %s)', $this->entity->name, $this->hash(), $this->entity->name, $this->objectID->hash(), $this->objectID->description(), $this->isFault ? "<fault>" : $this->dictionaryWithValues($this->entity->propertiesByName->filter(fn(PropertyDescription $property): bool => !$this->isRelationshipForKeyFault($property->name))->keys)->description());
    }

    public function debugDescription(): string
    {
        return sprintf("<%s: %s>", static::class, $this->hash());
    }
}
