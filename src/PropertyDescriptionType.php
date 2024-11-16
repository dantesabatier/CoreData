<?php

namespace Sabatier\CoreData;

/** @internal */
enum PropertyDescriptionType: int
{
    case private = -1;
    case attribute = 0;
    case derivedAttribute = 1;
    case compositeAttribute = 2;
    case fetchedProperty = 3;
    case expression = 4;
    case relationship = 1001;
}
