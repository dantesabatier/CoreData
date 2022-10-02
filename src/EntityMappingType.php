<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:39
 */

namespace Sabatier\CoreData;

/**
 * Class EntityMappingType
 *
 * The types for mapping an entity between a source model and a destination model.
 * @package Sabatier\CoreData
 */
enum EntityMappingType: int
{
    /** Specifies that the developer handles destination instance creation. */
    case undefinedEntityMappingType = 0;
    /** Specifies a custom mapping. */
    case customEntityMappingType = 1;
    /** Specifies that this is a new entity in the destination model. */
    case addEntityMappingType = 2;
    /** Specifies that this entity is not present in the destination model. */
    case removeEntityMappingType = 3;
    /** Specifies that source instances are migrated as-is. */
    case copyEntityMappingType = 4;
    /** Specifies that entity exists in source and destination and is mapped. */
    case transformEntityMappingType = 5;
}
