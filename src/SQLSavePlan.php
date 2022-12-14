<?php

namespace Sabatier\CoreData;

/** @internal */
readonly class SQLSavePlan
{
    public ManagedObjectContext $savingContext;
    public SaveChangesRequest $saveRequest;

    public function __construct(public SQLSaveChangesRequestContext $requestContext)
    {
        $this->savingContext = $this->requestContext->context;
        $this->saveRequest = $this->requestContext->request;
    }
}
