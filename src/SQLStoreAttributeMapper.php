<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

final readonly class SQLStoreAttributeMapper implements StoreAttributeMapper
{
    public function __construct(private PersistentStore $store, private ManagedObjectContext $context)
    {
    }
	
	#[Override]
    public function map(ManagedObject $object, Dictionary $mappedValues, Dictionary $snapshot): void
    {
    }
}
