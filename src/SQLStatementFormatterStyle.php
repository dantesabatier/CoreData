<?php

namespace Sabatier\CoreData;

/** @internal */
enum SQLStatementFormatterStyle: int
{
    public const string = 1;
    public const arguments = 2;
    public const prettyPrint = 4;
    public const highlighted = 8;
}
