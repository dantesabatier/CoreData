<?php

namespace Sabatier\CoreData;

/**
 * enum PersistentStoreStoreType
 * The types of persistent stores that Core Data supports.
 * @package Sabatier\CoreData
 */
enum PersistentStoreType: string
{
    /** A store that reads from and writes to a persistent binary file. */
    case binary = "binary";
    /** An ephemeral store that reads from and writes to memory only. */
    case inMemory = "inMemory";
    /** A store that reads from and writes to a persistent SQL database. */
    case sql = "sql";
    /** A store that reads from and writes to a persistent XML file. */
    case xml = "xml";
}
