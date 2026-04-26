<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/06/20
 * Time: 16:37
 */
namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;

/**
 * A concrete class that you use to represent basic nodes in a Core Data atomic store.
 */
class AtomicStoreCacheNode extends ObjectClass
{
    /** @var Dictionary<mixed> The property cache dictionary of the node. */
    public Dictionary $propertyCache;

    /**
     * Returns a cache node for the given managed object ID.
     * @param ManagedObjectID $objectID The managed object ID of the node.
     */
    public function __construct(public readonly ManagedObjectID $objectID)
    {
        $this->propertyCache = new Dictionary();
    }

    /**
     * Returns the value for a given key.
     *
     * The default implementation forwards the request to the {@see propertyCache()} dictionary if key matches a property name of the entity for the cache node. If key does not represent a property, the standard {@see ObjectClass::valueForKey()} implementation is used.
     * @param string $key The name of a property.
     * @return mixed The value for the property named key. For an attribute, the return value is an instance of an attribute type supported by Core Data (see {@see AttributeDescription}); for a to-one relationship, the return value must be another cache node instance; for a to-many relationship, the return value must be a collection of the related cache nodes.
     */
    #[Override]
    public function valueForKey(string $key): mixed
    {
        if ($this->objectID->entity->propertiesByName[$key]) {
            return $this->propertyCache[$key];
        }
        return parent::valueForKey($key);
    }

    /**
     * Sets the value for the given key.
     *
     * The default implementation forwards the request to the {@see propertyCache()} dictionary if key matches a property name of the entity for this cache node. If key does not represent a property, the standard {@see ObjectClass::setValueForKey()} implementation is used.
     * @param mixed $value The value for the property identified by $key.
     * @param string $key The name of a property.
     */
    #[Override]
    public function setValueForKey(mixed $value, string $key): void
    {
        if ($this->objectID->entity->propertiesByName[$key]) {
            $this->propertyCache[$key] = $value;
        } else {
            parent::setValueForKey($value, $key);
        }
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof IncrementalStoreNode) {
            return $this->objectID->isEqual($other->objectID);
        }
        return false;
    }
}
