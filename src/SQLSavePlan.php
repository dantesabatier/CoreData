<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

/** @internal */
final readonly class SQLSavePlan
{
    public ManagedObjectContext $savingContext;
    public SaveChangesRequest $saveRequest;

    public function __construct(public SQLSaveChangesRequestContext $requestContext)
    {
        $this->savingContext = $this->requestContext->context;
        $this->saveRequest = $this->requestContext->request;
    }
}
