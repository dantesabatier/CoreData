<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonPredicate;
use Sabatier\Foundation\CompoundPredicate;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Expression;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\PredicateOperatorType;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\ValueTransformer;

use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/** @internal */
class SQLPersistentHistoryChangeRequestContext extends SQLStoreRequestContext
{
    public function __construct(public readonly PersistentHistoryChangeRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($this->request, $context, $sqlCore);
        $this->isWritingRequest = $this->request->isDelete;
        $this->hasHistoryTracking = true;
    }

    /**
     * @throws Exception
     */
    private function createDeleteTransactionsRequestContext(): SQLSaveChangesRequestContext
    {
        if ($date = $this->request->date) {
            $request = PersistentHistoryChangeRequest::fetchHistoryAfterDate($date);
        } elseif ($transactionNumber = $this->request->transactionNumber) {
            $request = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(new PersistentHistoryTransaction(new Dictionary(['transactionNumber' => $transactionNumber->intValue])));
        } elseif ($fetchRequest = $this->request->fetchRequest) {
            $request = PersistentHistoryChangeRequest::fetchHistoryWithFetchRequest($fetchRequest);
        } else {
            $request = PersistentHistoryChangeRequest::fetchHistoryAfterToken($this->request->token);
        }
        $context = new SQLPersistentHistoryChangeRequestContext($request, $this->context, $this->sqlCore);
        $context->hasHistoryTracking = true;
        $context->executeRequestUsingConnection($this->connection);
        $context = new SQLSaveChangesRequestContext(new SaveChangesRequest(deletedObjects: new Set($context->result)), $this->context, $this->sqlCore);
        $context->hasHistoryTracking = true;
        return $context;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function createRequestContextForChangesWithTransactionIDs(ArrayClass $transactionIDs): SQLPersistentHistoryChangeRequestContext
    {
        return new SQLPersistentHistoryChangeRequestContext(PersistentHistoryChangeRequest::persistentHistoryChangesWithTransactionIDs($transactionIDs), $this->context, $this->sqlCore);
    }

    private function fetchRequestContextForChanges(): SQLFetchRequestContext
    {
        return new SQLFetchRequestContext($this->fetchRequestDescribingChanges(), $this->context, $this->sqlCore);
    }

    private function fetchRequestDescribingChanges(): FetchRequest
    {
        $request = $this->request;
        $persistentHistoryTransactionEntityDescription = PersistentHistoryTransaction::$entityDescription ?? throw new InternalInconsistencyException();
        if (!($fetchRequest = $request->fetchRequest)) {
            $fetchRequest = new FetchRequest();
            $fetchRequest->entity = $persistentHistoryTransactionEntityDescription;
        }
        $date = $request->date;
        $transactionNumber = $request->transactionNumber;
        $transactionIDs = $request->transactionIDs;
        $transactionKey = $fetchRequest->entity->isKindOf($persistentHistoryTransactionEntityDescription) ? "" : "transaction.";
        if ($request->isFetchTransactionForToken) {
            $token = $request->token ?? new PersistentHistoryToken(new Dictionary([$this->sqlCore->identifier => $transactionNumber ?? new Number(0)]));
            /** @psalm-suppress InvalidArgument */
            $predicate = CompoundPredicate::andPredicateWithSubpredicates($token->storeTokens->reduce(new Dictionary(), function (Dictionary $result, Number $value, string $key) use ($transactionKey): Dictionary {
                $result["{$transactionKey}storeID"] = $key;
                $result["{$transactionKey}transactionID"] = $value;
                return $result;
            })->map(fn(mixed $value, string $key): ComparisonPredicate => new ComparisonPredicate(Expression::expressionForKeyPath($key), Expression::expressionForConstantValue($value))));
        } elseif ($date) {
            $predicate = new ComparisonPredicate(Expression::expressionForKeyPath("{$transactionKey}timestamp"), Expression::expressionForConstantValue($date), PredicateOperatorType::greaterThan);
        } elseif ($transactionNumber) {
            $predicate = new ComparisonPredicate(Expression::expressionForKeyPath("{$transactionKey}transactionID"), Expression::expressionForConstantValue($transactionNumber), PredicateOperatorType::greaterThan);
        } elseif ($transactionIDs) {
            $predicate = new ComparisonPredicate(Expression::expressionForKeyPath("{$transactionKey}transactionID"), Expression::expressionForConstantValue($transactionIDs), PredicateOperatorType::in);
        } else {
            $predicate = new ComparisonPredicate(Expression::expressionForKeyPath("{$transactionKey}transactionID"), Expression::expressionForConstantValue(0), PredicateOperatorType::greaterThan);
        }
        if ($fetchRequestPredicate = $fetchRequest->predicate) {
            $fetchRequest->predicate = CompoundPredicate::andPredicateWithSubpredicates(new ArrayClass([$predicate, $fetchRequestPredicate]));
        } else {
            $fetchRequest->predicate = $predicate;
        }
        $fetchRequest->resultType = match ($request->resultType) {
            PersistentHistoryResultType::statusOnly, PersistentHistoryResultType::count => FetchRequestResultType::countResultType,
            PersistentHistoryResultType::objectIDs,
            PersistentHistoryResultType::transactionsOnly, PersistentHistoryResultType::changesOnly, PersistentHistoryResultType::transactionsAndChanges => FetchRequestResultType::dictionaryResultType,
        };
        /** @psalm-suppress PossiblyInvalidPropertyAssignmentValue */
        $fetchRequest->propertiesToFetch = match ($request->resultType) {
            PersistentHistoryResultType::transactionsOnly => $fetchRequest->entity->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$attribute instanceof DerivedAttributeDescription)->keys,
            PersistentHistoryResultType::objectIDs, PersistentHistoryResultType::changesOnly, PersistentHistoryResultType::transactionsAndChanges => $fetchRequest->entity->properties,
            default => $fetchRequest->propertiesToFetch
        };
        return $fetchRequest;
    }

    private function changeFromResult(Dictionary $dictionary): PersistentHistoryChange
    {
        $persistentStoreCoordinator = $this->sqlCore->persistentStoreCoordinator;
        /** @var ValueTransformer $valueTransformer */
        $valueTransformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName);
        /** @var string $data */
        $data = $dictionary['changedObjectID'];
        /** @var ManagedObjectID $changedObjectID */
        $changedObjectID = $valueTransformer->reverseTransformedValue($data);
        /** @psalm-suppress PossiblyNullPropertyAssignmentValue */
        $changedObjectID->entity = $persistentStoreCoordinator->managedObjectModel->entitiesByName[$changedObjectID->entityName];
        $changedObjectID->persistentStore = $persistentStoreCoordinator->persistentStores->first(fn(PersistentStore $persistentStore): bool => $persistentStore->identifier === $changedObjectID->storeIdentifier);
        $dictionary->removeValueForKey('changedObjectID');
        $dictionary->removeValueForKey('transaction');
        return new PersistentHistoryChange($dictionary, $changedObjectID);
    }

    private function transactionFromResult(Dictionary $dictionary): PersistentHistoryTransaction
    {
        /** @var Set<Dictionary>|null $changes */
        $changes = $dictionary['changes'];
        if ($changes) {
            $dictionary['changes'] = new ArrayClass($changes->map(fn(Dictionary $dictionary): PersistentHistoryChange => $this->changeFromResult($dictionary)));
        }
        return new PersistentHistoryTransaction($dictionary);
    }

    public function executeRequestCore(): bool
    {
        $request = $this->request;
        $connection = $this->connection;
        if ($request->isDelete) {
            if ($transactionNumber = $request->transactionNumber) {
                $connection->dropHistoryBeforeTransactionID($transactionNumber->intValue);
            } else {
                $context = $this->createDeleteTransactionsRequestContext();
                $context->executeRequestUsingConnection($connection);
            }
            if (!$connection->hasHistoryRows()) {
                $connection->dropHistoryTrackingTables();
            }
            return true;
        }
        if (!$connection->hasHistoryRows()) {
            return false;
        }
        $context = $this->fetchRequestContextForChanges();
        $context->executeRequestUsingConnection($this->connection);
        if ($request->resultType == PersistentHistoryResultType::statusOnly) {
            $this->result = new ArrayClass([new Number((bool)$context->result->sum())]);
            return true;
        }
        if ($request->resultType == PersistentHistoryResultType::count) {
            $this->result = $context->result;
            return true;
        }
        if ($context->request->entity->isKindOf(PersistentHistoryTransaction::$entityDescription ?? throw new InternalInconsistencyException())) {
            $transactions = $context->result->map(fn(Dictionary $dictionary): PersistentHistoryTransaction => $this->transactionFromResult($dictionary));
            $this->result = match ($request->resultType) {
                PersistentHistoryResultType::objectIDs => $transactions->flatMap(fn(PersistentHistoryTransaction $transaction): iterable => $transaction->changes?->map(fn(PersistentHistoryChange $change): ManagedObjectID => $change->changedObjectID) ?? []),
                PersistentHistoryResultType::transactionsOnly, PersistentHistoryResultType::transactionsAndChanges => $transactions,
                PersistentHistoryResultType::changesOnly => $transactions->flatMap(fn(PersistentHistoryTransaction $transaction): iterable => $transaction->changes ?? []),
                default => new ArrayClass(),
            };
        } else {
            $changes = $context->result->map(fn(Dictionary $dictionary): PersistentHistoryChange => $this->changeFromResult($dictionary));
            $this->result = match ($request->resultType) {
                PersistentHistoryResultType::objectIDs => $changes->map(fn(PersistentHistoryChange $change): ManagedObjectID => $change->changedObjectID),
                PersistentHistoryResultType::transactionsOnly, PersistentHistoryResultType::transactionsAndChanges => new ArrayClass([new PersistentHistoryTransaction(new Dictionary(['changes' => $changes]))]),
                PersistentHistoryResultType::changesOnly => $changes,
                default => new ArrayClass(),
            };
        }
        return true;
    }
}
