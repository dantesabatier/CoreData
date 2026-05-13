<?php

namespace Sabatier\CoreData;

use Exception;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\CompoundPredicate;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\ValueTransformer;
use function Sabatier\Foundation\fatal_error;
use const Sabatier\Foundation\SecureUnarchiveFromDataTransformerName;

/** @internal */
final class SQLPersistentHistoryChangeRequestContext extends SQLStoreRequestContext
{
    public PersistentHistoryChangeRequest $request {
        get {
            /** @var PersistentHistoryChangeRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    #[Override]
    public bool $isWritingRequest {
        get => $this->request->isDelete;
    }
    #[Override]
    public bool $hasHistoryTracking = true;
    private FetchRequest $fetchRequestDescribingChanges {
        get {
            if (isset($this->fetchRequestDescribingChanges)) {
                return $this->fetchRequestDescribingChanges;
            }
            $request = $this->request;
            $persistentHistoryTransactionEntityDescription = PersistentHistoryTransaction::$entityDescription ?? fatal_error("PersistentHistoryTransaction entity description is not initialized");
            if (!($fetchRequest = $request->fetchRequest)) {
                $fetchRequest = new FetchRequest();
                $fetchRequest->entity = $persistentHistoryTransactionEntityDescription;
            }
            $date = $request->date;
            $transactionNumber = $request->transactionNumber;
            $transactionIDs = $request->transactionIDs;
            $transactionKey = $fetchRequest->entity?->isKindOf($persistentHistoryTransactionEntityDescription) ? "" : "transaction.";
            if ($request->isFetchTransactionForToken) {
                $token = $request->token ?? new PersistentHistoryToken(new Dictionary([$this->sqlCore->identifier => $transactionNumber ?? new Number(0)]));
                /** @psalm-suppress ReservedWord */
                $predicate = CompoundPredicate::andPredicateWithSubpredicates($token->storeTokens->reduce(new Dictionary(),
                    /**
                     * @param Dictionary<mixed> $result
                     * @param Number $value
                     * @param string $key
                     * @return Dictionary<mixed>
                     */
                    function (Dictionary $result, Number $value, string $key) use ($transactionKey): Dictionary {
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
            /** @var EntityDescription $entity */
            $entity = $fetchRequest->entity ?? fatal_error("Entity cannot be null");
            /** @psalm-suppress PossiblyInvalidPropertyAssignmentValue */
            $fetchRequest->propertiesToFetch = match ($request->resultType) {
                PersistentHistoryResultType::transactionsOnly => $entity->attributesByName->filter(fn(AttributeDescription $attribute): bool => !$attribute instanceof DerivedAttributeDescription)->keys,
                PersistentHistoryResultType::objectIDs, PersistentHistoryResultType::changesOnly, PersistentHistoryResultType::transactionsAndChanges => $entity->properties,
                default => $fetchRequest->propertiesToFetch
            };
            return $this->fetchRequestDescribingChanges = $fetchRequest;
        }
    }
    private SQLFetchRequestContext $fetchRequestContextForChanges {
        get => $this->fetchRequestContextForChanges ??= new SQLFetchRequestContext($this->fetchRequestDescribingChanges, $this->context, $this->sqlCore);
    }
    private SQLSaveChangesRequestContext $deleteTransactionsRequestContext {
        /**
         * @throws Exception
         */
        get {
            if (isset($this->deleteTransactionsRequestContext)) {
                return $this->deleteTransactionsRequestContext;
            }
            if ($date = $this->request->date) {
                $request = PersistentHistoryChangeRequest::fetchHistoryAfterDate($date);
            } elseif ($transactionNumber = $this->request->transactionNumber) {
                $request = PersistentHistoryChangeRequest::fetchHistoryAfterTransaction(new PersistentHistoryTransaction(new Dictionary(["transactionNumber" => $transactionNumber->intValue])));
            } elseif ($fetchRequest = $this->request->fetchRequest) {
                $request = PersistentHistoryChangeRequest::fetchHistoryWithFetchRequest($fetchRequest);
            } else {
                $request = PersistentHistoryChangeRequest::fetchHistoryAfterToken($this->request->token);
            }
            $context = new SQLPersistentHistoryChangeRequestContext($request, $this->context, $this->sqlCore);
            $context->executeRequestUsingConnection($this->connection);
            $deleteTransactionsRequestContext = new SQLSaveChangesRequestContext(new SaveChangesRequest(deletedObjects: new Set($context->result)), $this->context, $this->sqlCore);
            $deleteTransactionsRequestContext->hasHistoryTracking = true;
            return $this->deleteTransactionsRequestContext = $deleteTransactionsRequestContext;
        }
    }


    public function __construct(PersistentHistoryChangeRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        if ($this->request->isDelete) {
            if ($transactionNumber = $this->request->transactionNumber) {
                $this->connection->dropHistoryBeforeTransactionID($transactionNumber->intValue);
            } else {
                $this->deleteTransactionsRequestContext->executeRequestUsingConnection($this->connection);
            }
            if ($this->connection->hasHistoryRows()) {
                $this->sqlCore->recomputePrimaryKeyMaxForEntities($this->sqlCore->model->entities->filter(fn(SQLEntity $entity): bool => $entity->entityDescription->isPersistentHistoryEntity));
            } else {
                $this->connection->dropHistoryTrackingTables();
            }
            return true;
        }
        if (!$this->connection->hasHistoryRows()) {
            return false;
        }
        $context = $this->fetchRequestContextForChanges;
        $context->executeRequestUsingConnection($this->connection);
        $this->result = match ($this->request->resultType) {
            PersistentHistoryResultType::statusOnly => new ArrayClass([new Number((bool)$context->result->sum())]),
            PersistentHistoryResultType::count => $context->result,
            default => (function () use ($context): ArrayClass {
                if ($context->request->entity?->isKindOf(PersistentHistoryTransaction::$entityDescription ?? fatal_error("PersistentHistoryTransaction entity description is not initialized"))) {
                    $transactions = $context->result->map($this->transactionFromResult(...));
                    return match ($this->request->resultType) {
                        PersistentHistoryResultType::objectIDs => $transactions->flatMap(fn(PersistentHistoryTransaction $transaction): iterable => $transaction->changes?->map(fn(PersistentHistoryChange $change): ManagedObjectID => $change->changedObjectID) ?? []),
                        PersistentHistoryResultType::changesOnly => $transactions->flatMap(fn(PersistentHistoryTransaction $transaction): iterable => $transaction->changes ?? []),
                        default => $transactions
                    };
                }
                $changes = $context->result->compactMap($this->changeFromResult(...));
                return match ($this->request->resultType) {
                    PersistentHistoryResultType::objectIDs => $changes->map(fn(PersistentHistoryChange $change): ManagedObjectID => $change->changedObjectID),
                    PersistentHistoryResultType::transactionsOnly, PersistentHistoryResultType::transactionsAndChanges => new ArrayClass([new PersistentHistoryTransaction(new Dictionary(["changes" => $changes]))]),
                    default => $changes
                };
            })()
        };
        return true;
    }

    private function transactionFromResult(Dictionary $dictionary): PersistentHistoryTransaction
    {
        /** @var Set<Dictionary<mixed>>|null $changes */
        $changes = $dictionary["changes"];
        if ($changes) {
            $dictionary["changes"] = new ArrayClass($changes->compactMap($this->changeFromResult(...)));
        }
        return new PersistentHistoryTransaction($dictionary);
    }

    private function changeFromResult(Dictionary $dictionary): ?PersistentHistoryChange
    {
        $persistentStoreCoordinator = $this->sqlCore->persistentStoreCoordinator;
        /** @var ValueTransformer $valueTransformer */
        $valueTransformer = ValueTransformer::valueTransformerForName(SecureUnarchiveFromDataTransformerName);
        /** @var string $data */
        $data = $dictionary["changedObjectID"];
        $dictionary->removeAll(fn(mixed $value, string $key): bool => match ($key) {
            "entityName", "changedObjectID", "transaction" => true,
            default => false
        });
        $changedObjectID = $valueTransformer->reverseTransformedValue($data);
        if (!$changedObjectID instanceof ManagedObjectID) {
            return null;
        }
        if (!($entity = $persistentStoreCoordinator->managedObjectModel->entitiesByName[$changedObjectID->entityName])) {
            return null;
        }
        $changedObjectID->entity = $entity;
        $changedObjectID->persistentStore = $persistentStoreCoordinator->persistentStores->first(fn(PersistentStore $persistentStore): bool => $persistentStore->identifier === $changedObjectID->storeIdentifier);
        return new PersistentHistoryChange($dictionary, $changedObjectID);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function createRequestContextForChangesWithTransactionIDs(ArrayClass $transactionIDs): SQLPersistentHistoryChangeRequestContext
    {
        return new SQLPersistentHistoryChangeRequestContext(PersistentHistoryChangeRequest::persistentHistoryChangesWithTransactionIDs($transactionIDs), $this->context, $this->sqlCore);
    }
}
