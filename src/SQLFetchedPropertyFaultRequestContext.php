<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;
use function Sabatier\Foundation\fatal_error;

final class SQLFetchedPropertyFaultRequestContext extends SQLStoreRequestContext
{
    public FetchRequest $fetchRequest {
        get {
            /** @var FetchRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }

    public function __construct(ManagedObjectID $objectID, FetchedPropertyDescription $fetchedProperty, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        $fetchRequest = $fetchedProperty->fetchRequest ?? fatal_error("Fetched property \"$fetchedProperty->name\" fetchRequest cannot be null.");
        $entityName = $fetchRequest->entityName ?? fatal_error("FetchRequest for fetched property \"$fetchedProperty->name\" entityName cannot be null.");
        $predicate = $fetchRequest->predicate ?? fatal_error("FetchRequest for fetched property \"$fetchedProperty->name\" predicate cannot be null.");
        /** @var FetchRequest<ManagedObjectID> $request */
        $request = clone($fetchRequest, [
            "entity" => EntityDescription::entity($entityName, $context),
            "predicate" => $predicate->withSubstitutionVariables(new Dictionary(["\$FETCH_SOURCE" => $objectID->referenceObject, "\$FETCHED_PROPERTY" => $fetchedProperty])),
            "resultType" => FetchRequestResultType::managedObjectIDResultType,
        ]);
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        $debugLevel = $this->debugLevel;
        $this->debugLevel = SQLDebugLevel::none;
        $fetchRequestContext = new SQLFetchRequestContext($this->fetchRequest, $this->context, $this->sqlCore);
        $fetchRequestContext->executeRequestUsingConnection($this->connection);
        $this->result = $fetchRequestContext->result;
        $this->debugLevel = $debugLevel;
        return true;
    }
}
