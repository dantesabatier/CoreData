<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class SnapshotValueMapper
{
    private SnapshotMappingStrategyResolver $resolver {
        get => $this->resolver ??= new SnapshotMappingStrategyResolver($this->store, $this->context);
    }

    public function __construct(private readonly PersistentStore $store, private readonly ManagedObjectContext $context)
    {
    }

    public function mapSnapshot(ManagedObject $object, Dictionary $snapshot): Dictionary
    {
        return $this->resolver->strategy->mapValues($object, $snapshot);
    }
}
