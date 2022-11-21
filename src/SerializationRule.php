<?php

/**
 * @author Dante Sabatier <dantesabatier@me.com>
 * @version 1.0
 */

namespace Sabatier\CoreData;

enum SerializationRule: int
{
    case attributesOnly = 0;
    case attributesAndRelationships = 1;
    case inferred = 2;
    case custom = 3;
}
