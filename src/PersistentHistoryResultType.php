<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:52
 */

namespace Sabatier\CoreData;

/**
 * Class PersistentHistoryResultType
 * The types of results from a persistent history change request.
 * @package Sabatier\CoreData
 */
enum PersistentHistoryResultType: int
{
    /** The status of the persistent history change request. */
    case statusOnly = 0;
    /** The identifiers of managed objects changed since the requested date, token, or transaction. */
    case objectIDs = 1;
    /** The number of persistent history changes since the requested date, token, or transaction. */
    case count = 2;
    /** The persistent history transactions since the requested date, token, or transaction. */
    case transactionsOnly = 3;
    /** The persistent history changes since the requested date, token, or transaction. */
    case changesOnly = 4;
    /** The persistent history transactions and changes since the requested date, token, or transaction. */
    case transactionsAndChanges = 5;
}
