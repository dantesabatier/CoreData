<?php

namespace Sabatier\CoreData;

/** @internal */
enum PropertyDescriptionType: int
{
    case private = -1;
    case attribute = 0;
    case derivedAttribute = 1;
    case fetchedProperty = 2;
    case expression = 3;
    case relationship = 1001;
}
