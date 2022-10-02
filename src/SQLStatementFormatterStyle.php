<?php

namespace Sabatier\CoreData;

/** @internal */
enum SQLStatementFormatterStyle: int
{
    const string = 1;
    const arguments = 2;
    const prettyPrint = 4;
    const highlighted = 8;
}
