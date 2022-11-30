<?php

namespace Sabatier\CoreData;

enum SerializationRule: int
{
    case attributesOnly = 0;
    case attributesAndRelationships = 1;
    case inferred = 2;
    case custom = 3;
}
