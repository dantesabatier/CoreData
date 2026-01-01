<?php

namespace Sabatier\CoreData;

/** @internal */
enum SQLDebugLevel: int
{
    case none = 0;
    case rawSQL = 1;
    case sqlWithParams = 2;
    case prettyFormatSQL = 3;
    case includeResults = 4;
    case analyzeJSON = 5;
}
