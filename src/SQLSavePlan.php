<?php

namespace Sabatier\CoreData;

/** @internal */
class SQLSavePlan
{
    public readonly ManagedObjectContext $savingContext;
    public readonly SaveChangesRequest $saveRequest;

    public function __construct(public readonly SQLSaveChangesRequestContext $requestContext)
    {
        $this->savingContext = $this->requestContext->context;
        $this->saveRequest = $this->requestContext->request;
    }
}