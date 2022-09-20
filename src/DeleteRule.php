<?php

/**
 * @author Dante Sabatier <dantesabatier@me.com>
 * @version 1.0
 * @package Sabatier\CoreData
 */

namespace Sabatier\CoreData;

/**
 * Enum DeleteRule
 * Constants that determine what happens when you delete a relationship's owning managed object.
 * @package Sabatier\CoreData
 */
enum DeleteRule: int
{
    /** A rule that prevents modification of the referenced managed objects. If you use this delete rule, make sure you delete any referenced managed objects or nullify their inverse relationships. Otherwise, those objects will reference an object that doesn't exist, and your persistent store will be in an inconsistent state. */
    case noActionDeleteRule = 0;
    /** A rule that nullifies the inverse relationship of the referenced managed objects. */
    case nullifyDeleteRule = 1;
    /** A rule that deletes the referenced managed objects. */
    case cascadeDeleteRule = 2;
    /** A rule that prevents the deletion of the owning managed object if the relationship has references to other objects. */
    case denyDeleteRule = 3;
}
