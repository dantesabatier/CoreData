<?php

namespace Sabatier\CoreData;

/** @internal */
class SQLStatementFormatterStyle
{
    final const none = 0;
    final const string = 1;
    final const arguments = 2;
    final const prettyPrint = 4;
    final const highlighted = 8;
}
