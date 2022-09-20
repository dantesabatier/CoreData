<?php

namespace Sabatier\CoreData;

/** @internal */
enum SQLFormatterTokenType: int
{
    case whitespace = 0;
    case word = 1;
    case quote = 2;
    case backtickQuote = 3;
    case reserved = 4;
    case reservedToplevel = 5;
    case reservedNewline = 6;
    case boundary = 7;
    case comment = 8;
    case blockComment = 9;
    case number = 10;
    case error = 11;
    case variable = 12;
    case column = 13;
    case function = 14;
}