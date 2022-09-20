<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/07/20
 * Time: 06:18
 */

namespace Sabatier\CoreData;

/**
 * Class MergePolicyType
 * Constants that define merge policy types.
 * @package Sabatier\CoreData
 */
enum MergePolicyType: int
{
    /** The default merge policy for all managed object contexts. If a save fails because of conflicting objects, you can find the IDs of those objects in error's userInfo dictionary. Use the {@see InsertedObjectsKey} and {@see UpdatedObjectsKey} keys to extract the object IDs. */
    case errorMergePolicyType = 0;
    /** A property-based merge policy that applies external changes. A policy that merges conflicts between the persistent store's version of the object and the current in-memory version by individual property, with external changes trumping in-memory changes. */
    case mergeByPropertyStoreTrumpMergePolicyType = 1;
    /** A property-based merge policy that applies in-memory changes. A policy that merges conflicts between the persistent store's version of the object and the current in-memory version by individual property, with in-memory changes trumping external changes. */
    case mergeByPropertyObjectTrumpMergePolicyType = 2;
    /** A merge policy type that overwrites the entire stored object. This policy merges conflicts between the persistent store's version of the object and the current in-memory version by saving the entire in-memory object to the persistent store. */
    case overwriteMergePolicyType = 3;
    /** A merge policy that discards unsaved changes. This policy merges conflicts between the persistent store's version of the object and the current in-memory version by discarding unsaved changes. */
    case rollbackMergePolicyType = 4;
}
