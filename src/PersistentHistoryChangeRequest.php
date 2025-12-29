<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:48
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Number;

/**
 * A request to fetch or purge persistent history.
 */
final class PersistentHistoryChangeRequest extends PersistentStoreRequest
{
    /** @var FetchRequest|null The specified fetch request, when retrieving history. */
    public ?FetchRequest $fetchRequest = null;
    /** @var PersistentHistoryResultType The type of result that this request returns. This value defaults to PersistentHistoryResultType.transactionsAndChanges. */
    public PersistentHistoryResultType $resultType = PersistentHistoryResultType::transactionsAndChanges;
    /** @var PersistentHistoryToken|null The specified token, when retrieving history defined by a token. */
    private(set) ?PersistentHistoryToken $token;
    /** @internal */
    private(set) ?ArrayClass $transactionIDs;
    /** @internal */
    private(set) ?Number $transactionNumber;
    /** @internal */
    private(set) bool $isDelete = false;
    /** @internal */
    private(set) ?Date $date = null;

    private function __construct(?PersistentHistoryToken $token = null, ?FetchRequest $fetchRequest = null, ?ArrayClass $transactionIDs = null, ?int $transactionNumber = null, ?Date $date = null, bool $isDelete = false, bool $isTransactionOnly = false, public readonly bool $isFetchTransactionForToken = false)
    {
        parent::__construct();
        $this->token = $token;
        $this->fetchRequest = $fetchRequest;
        $this->transactionIDs = $transactionIDs;
        $this->transactionNumber = $transactionNumber !== null ? new Number($transactionNumber) : null;
        $this->date = $date;
        $this->isDelete = $isDelete;
        $this->resultType = $transactionIDs ? PersistentHistoryResultType::objectIDs : ($isTransactionOnly ? PersistentHistoryResultType::transactionsOnly : PersistentHistoryResultType::transactionsAndChanges);
    }

    /** @internal */
    public static function persistentHistoryChangesWithTransactionIDs(ArrayClass $transactionIDs): PersistentHistoryChangeRequest
    {
        return new self(transactionIDs: $transactionIDs);
    }

    /**
     * Retrieves history since a given date.
     * @param Date $date The date used to define the start of the fetch history.
     * @return PersistentHistoryChangeRequest A persistent history fetch request ({@see PersistentHistoryChangeRequest}) with an initial date boundary.
     */
    public static function fetchHistoryAfterDate(Date $date): PersistentHistoryChangeRequest
    {
        return new self(date: $date);
    }

    /**
     * Retrieves the request history after a given token.
     * @param PersistentHistoryToken|null $token The bookmark that defines the start of the request history.
     * @return PersistentHistoryChangeRequest A persistent history fetch request ({@see PersistentHistoryChangeRequest}) with an initial token bookmark boundary.
     */
    public static function fetchHistoryAfterToken(?PersistentHistoryToken $token): PersistentHistoryChangeRequest
    {
        return new self($token, isFetchTransactionForToken: true);
    }

    /**
     * Retrieves history since a given transaction.
     * @param PersistentHistoryTransaction|null $transaction The transaction that marks the beginning of the history request.
     * @return PersistentHistoryChangeRequest A persistent history fetch request ({@see PersistentHistoryChangeRequest}) with an initial transaction boundary.
     */
    public static function fetchHistoryAfterTransaction(?PersistentHistoryTransaction $transaction): PersistentHistoryChangeRequest
    {

        return new self(transactionNumber: $transaction?->transactionNumber ?? 0);
    }

    /**
     * Retrieves history based on a fetch request.
     * @param FetchRequest $fetchRequest The fetch request that defines the history bounds.
     * @return PersistentHistoryChangeRequest A persistent history fetch request ({@see PersistentHistoryChangeRequest}) built using an existing fetch request.
     */
    public static function fetchHistoryWithFetchRequest(FetchRequest $fetchRequest): PersistentHistoryChangeRequest
    {
        return new self(fetchRequest: $fetchRequest);
    }

    /**
     * Purges history older than a given date.
     * @param Date $date The date used to define the end of the delete history request.
     * @return PersistentHistoryChangeRequest A delete history change request ({@see PersistentHistoryChangeRequest}) using an end date boundary.
     */
    public static function deleteHistoryBeforeDate(Date $date): PersistentHistoryChangeRequest
    {
        return new self(date: $date, isDelete: true, isTransactionOnly: true);
    }

    /**
     * Purges history older than that defined by a given token.
     * @param PersistentHistoryToken|null $token The bookmark that marks the end of the delete history request.
     * @return PersistentHistoryChangeRequest A delete history change request ({@see PersistentHistoryChangeRequest}) using an end token bookmark boundary.
     */
    public static function deleteHistoryBeforeToken(?PersistentHistoryToken $token): PersistentHistoryChangeRequest
    {
        return new self($token, isDelete: true, isTransactionOnly: true, isFetchTransactionForToken: true);
    }

    /**
     * Purges history older than a given transaction.
     * @param PersistentHistoryTransaction|null $transaction The transaction that marks the end of the delete history request.
     * @return PersistentHistoryChangeRequest A delete history change request ({@see PersistentHistoryChangeRequest}) using an end date boundary.
     */
    public static function deleteHistoryBeforeTransaction(?PersistentHistoryTransaction $transaction): PersistentHistoryChangeRequest
    {
        return new self(transactionNumber: $transaction?->transactionNumber ?? PHP_INT_MAX, isDelete: true, isTransactionOnly: true);
    }
}
