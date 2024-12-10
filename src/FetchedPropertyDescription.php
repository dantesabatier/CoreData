<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 30/06/20
 * Time: 16:50
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\KeyValueCoding;

/**
 * A description object used to define which properties are fetched from Core Data.
 *
 * An example might be a iTunes playlist, if expressed as a property of a containing object. Songs don't belong to a particular playlist, especially in the case that they're on a remote server. The playlist may remain even after the songs have been deleted, or the remote server has become inaccessible. Note, however, that unlike a playlist a fetched property is static—it does not dynamically update itself as objects in the destination entity change.
 * The effect of a fetched property is similar to executing a fetch request yourself and placing the results in a transient attribute, although with the framework managing the details. In particular, a fetched property is not fetched until it is requested, and the results are then cached until the object is turned into a fault. You use {@see ManagedObjectContext::refresh()} (ManagedObjectContext) to manually refresh the properties—this causes the fetch request associated with this property to be executed again when the object fault is next fired.
 * Unlike other relationships, which are all sets, fetched properties are represented by an ordered Array object just as if you executed the fetch request yourself. The fetch request associated with the property can have a sort ordering. The value for a fetched property of a managed object does not support {@see KeyValueCoding::mutableArrayValueForKey()}.
 * Fetch requests set on a fetched property have 2 special variable bindings you can use: $FETCH_SOURCE and $FETCHED_PROPERTY. The source refers to the specific managed object that has this property; the property refers to the FetchedPropertyDescription object itself (which may have a user info associated with it that you want to use).
 */
class FetchedPropertyDescription extends PropertyDescription
{
    /** @internal */
    public PropertyDescriptionType $propertyType = PropertyDescriptionType::fetchedProperty;
    /** @var FetchRequest|null The fetch request of the receiver. */
    public ?FetchRequest $fetchRequest = null;

    #[Override]
    public function jsonSerialize(): Dictionary
    {
        $dictionary = parent::jsonSerialize();
        if ($fetchRequest = $this->fetchRequest) {
            $dictionary["fetchRequestEntityName"] = $fetchRequest->entityName;
            $dictionary["fetchRequestPredicateFormat"] = $fetchRequest->predicate?->predicateFormat;
        }
        return $dictionary;
    }
}
