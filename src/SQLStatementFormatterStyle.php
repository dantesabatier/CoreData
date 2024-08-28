<?php

namespace Sabatier\CoreData;

/** @internal */
class SQLStatementFormatterStyle
{
    final const int none = 0;
    final const int string = 1;
    final const int arguments = 2;
    final const int prettyPrint = 4;
    final const int highlighted = 8;
}
