<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 15/06/20
 * Time: 22:53
 */
namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;

/**
 * A concrete class used to represent basic nodes in a Core Data incremental store.
 */
final class IncrementalStoreNode extends ObjectClass
{
    /**
     * Returns an object initialized with the given values.
     * @param ManagedObjectID $objectID The object ID that identifies the data stored by the receiver.
     * @param Dictionary<mixed> $values
     * @param int $version The version of data in the receiver.
     */
    public function __construct(public readonly ManagedObjectID $objectID, public readonly Dictionary $values, public int $version = 0)
    {
    }

    /**
     * Update the values and version to reflect new data being saved to or loaded from the external store.
     * @param Dictionary<mixed> $values
     * @param int $version
     */
    public function updateWithValues(Dictionary $values, int $version = 0): void
    {
        $this->values->merge($values);
        if ($version === 0) {
            $version = $this->version + 1;
        }
        $this->version = $version;
    }

    /**
     * Returns the value for the given property.
     * @param PropertyDescription $property
     * @return mixed
     */
    public function valueForProperty(PropertyDescription $property): mixed
    {
        return $this->values[$property->name];
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
