<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class HydrationStrategyService
{
    private HydrationStrategyResolver $resolver {
        get => $this->resolver ??= new HydrationStrategyResolver($this->store, $this->context);
    }

    public function __construct(private readonly PersistentStore $store, private readonly ManagedObjectContext $context)
    {
    }

    public function transform(ManagedObject $object, Dictionary $snapshot): Dictionary
    {
        return $this->resolver->strategy->mapValues($object, $snapshot);
    }
}
