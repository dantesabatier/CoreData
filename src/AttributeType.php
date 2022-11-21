<?php

/**
 * @author Dante Sabatier <dantesabatier@me.com>
 * @version 1.0
 */

namespace Sabatier\CoreData;

/**
 * The types of attributes that Core Data supports.
 */
enum AttributeType: int
{
    /** An attribute that doesn't have an explicit type. */
    case undefined = 0;
    /** An attribute that stores a 16-bit signed integer value. */
    case integer16 = 100;
    /** An attribute that stores a 32-bit signed integer value. */
    case integer32 = 200;
    /** An attribute that stores a 64-bit signed integer value. */
    case integer64 = 300;
    /** An attribute that stores a decimal value. */
    case decimal = 400;
    /** An attribute that stores a double value. */
    case double = 500;
    /** An attribute that stores a float value. */
    case float = 600;
    /** An attribute that stores a string. */
    case string = 700;
    /** An attribute that stores a Boolean value. */
    case boolean = 800;
    /** An attribute that stores a date. */
    case date = 900;
    /** An attribute that stores binary data. */
    case binaryData = 1000;
    /** An attribute that stores a universally unique identifier. */
    case uuid = 1100;
    /** An attribute that stores a uniform resource identifier. */
    case uri = 1200;
    /** An attribute that derives its value from a value transformer. */
    case transformable = 1800;
    /** An attribute that stores a managed object's ID. */
    case objectID = 2000;
}
