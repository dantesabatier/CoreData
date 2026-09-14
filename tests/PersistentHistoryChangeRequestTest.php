<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\PersistentHistoryChangeRequest;
use Sabatier\CoreData\PersistentHistoryResultType;
use Sabatier\CoreData\PersistentHistoryToken;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;

/**
 * Covers the factory methods of PersistentHistoryChangeRequest — the public surface for querying
 * and purging the persistent history log.
 *
 * The class has a private constructor and seven named factories, each of which fixes a different
 * combination of three things: which boundary the request carries (a date, a token, a transaction
 * number, a fetch request), whether it fetches or purges, and which result type it asks the store
 * for. Those combinations are the contract — a purge that reported transactionsAndChanges would
 * make the store materialize every change it is about to delete — and nothing exercised them.
 *
 * No store is involved: these assert how a request is configured, not what executing it returns.
 */
final class PersistentHistoryChangeRequestTest extends TestCase
{
    private static function token(int $transactionNumber = 7): PersistentHistoryToken
    {
        return new PersistentHistoryToken(new Dictionary(["store" => new Number($transactionNumber)]));
    }

    /**
     * A fetch by date carries the date and asks for the full payload — transactions with their
     * changes, which is what a client replaying history since a point in time needs.
     */
    public function testFetchHistoryAfterDateCarriesTheDateAndAsksForChanges(): void
    {
        $date = Date::now();

        $request = PersistentHistoryChangeRequest::fetchHistoryAfterDate($date);

        $this->assertSame($date, $request->date);
        $this->assertFalse($request->isDelete, "fetching is not purging");
        $this->assertSame(PersistentHistoryResultType::transactionsAndChanges, $request->resultType);
    }

    /**
     * A fetch by token keeps the token and flags itself as token-driven, which is how the store
     * knows to resolve the bookmark into a transaction number per store rather than using a
     * single global boundary.
     */
    public function testFetchHistoryAfterTokenCarriesTheTokenAndItsFlag(): void
    {
        $token = self::token();

        $request = PersistentHistoryChangeRequest::fetchHistoryAfterToken($token);

        $this->assertSame($token, $request->token);
        $this->assertTrue($request->isFetchTransactionForToken, "the store must resolve the bookmark per store");
        $this->assertFalse($request->isDelete);
    }

    /**
     * A fetch driven by a fetch request keeps that request as its bound and stays a full fetch.
     */
    public function testFetchHistoryWithFetchRequestCarriesTheRequest(): void
    {
        $fetchRequest = new FetchRequest("Person");

        $request = PersistentHistoryChangeRequest::fetchHistoryWithFetchRequest($fetchRequest);

        $this->assertSame($fetchRequest, $request->fetchRequest);
        $this->assertFalse($request->isDelete);
        $this->assertSame(PersistentHistoryResultType::transactionsAndChanges, $request->resultType);
    }

    /**
     * Asking for specific transaction IDs switches the result type to objectIDs: the caller
     * already knows which transactions it wants and needs the objects they touched, not the
     * transaction records themselves.
     */
    public function testRequestForTransactionIDsAsksForObjectIDs(): void
    {
        $transactionIDs = new ArrayClass([new Number(1), new Number(2)]);

        $request = PersistentHistoryChangeRequest::persistentHistoryChangesWithTransactionIDs($transactionIDs);

        $this->assertSame($transactionIDs, $request->transactionIDs);
        $this->assertSame(PersistentHistoryResultType::objectIDs, $request->resultType);
        $this->assertFalse($request->isDelete);
    }

    /**
     * Every purge asks for transactionsOnly. A delete never needs the changes inside the
     * transactions it removes, and asking for them would make the store load the entire history
     * being discarded.
     */
    public function testEveryPurgeAsksForTransactionsOnly(): void
    {
        $purges = new ArrayClass([
            PersistentHistoryChangeRequest::deleteHistoryBeforeDate(Date::now()),
            PersistentHistoryChangeRequest::deleteHistoryBeforeToken(self::token()),
            PersistentHistoryChangeRequest::deleteHistoryBeforeTransaction(null),
        ]);

        $purges->forEach(function (PersistentHistoryChangeRequest $request): void {
            $this->assertTrue($request->isDelete, "a purge is flagged as a delete");
            $this->assertSame(PersistentHistoryResultType::transactionsOnly, $request->resultType, "and never asks for the changes it is discarding");
        });
    }

    /**
     * A purge by date carries its boundary the same way the fetch does.
     */
    public function testDeleteHistoryBeforeDateCarriesTheDate(): void
    {
        $date = Date::now();

        $request = PersistentHistoryChangeRequest::deleteHistoryBeforeDate($date);

        $this->assertSame($date, $request->date);
    }

    /**
     * A purge by token keeps both the token and the token-resolution flag, so it deletes up to
     * the same boundary the matching fetch would have read to.
     */
    public function testDeleteHistoryBeforeTokenCarriesTheTokenAndItsFlag(): void
    {
        $token = self::token();

        $request = PersistentHistoryChangeRequest::deleteHistoryBeforeToken($token);

        $this->assertSame($token, $request->token);
        $this->assertTrue($request->isFetchTransactionForToken);
    }

    /**
     * A purge with no transaction deletes everything: the boundary defaults to PHP_INT_MAX, so
     * "before no particular transaction" means the whole log.
     *
     * The fetch counterpart defaults the other way, to 0 — "after no particular transaction"
     * means from the beginning. Both read as "unbounded", and they are opposite numbers because
     * one bound is an upper and the other a lower.
     */
    public function testAnUnboundedPurgeAndAnUnboundedFetchDefaultToOppositeEnds(): void
    {
        $purge = PersistentHistoryChangeRequest::deleteHistoryBeforeTransaction(null);
        $fetch = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(null);

        $this->assertSame(PHP_INT_MAX, $purge->transactionNumber?->intValue, "a purge with no bound removes the whole log");
        $this->assertSame(0, $fetch->transactionNumber?->intValue, "a fetch with no bound reads from the beginning");
    }

    /**
     * The token-resolution flag is what separates a token-bounded request from every other kind,
     * so a request built from a date or a transaction must not claim it.
     */
    public function testOnlyTokenBoundedRequestsClaimTokenResolution(): void
    {
        $this->assertFalse(PersistentHistoryChangeRequest::fetchHistoryAfterDate(Date::now())->isFetchTransactionForToken);
        $this->assertFalse(PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(null)->isFetchTransactionForToken);
        $this->assertFalse(PersistentHistoryChangeRequest::deleteHistoryBeforeDate(Date::now())->isFetchTransactionForToken);
    }
}
