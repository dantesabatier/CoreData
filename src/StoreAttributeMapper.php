<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
abstract readonly class StoreAttributeMapper
{
    public function __construct(protected PersistentStore $store, protected ManagedObjectContext $context)
    {
    }

    /**
     * @param ManagedObject $object
     * @param Dictionary<mixed> $mappedValues
     * @param Dictionary<mixed> $snapshot
     */
    abstract public function map(ManagedObject $object, Dictionary $mappedValues, Dictionary $snapshot): void;
}
