<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
final class SnapshotValueMapper
{
    private SnapshotMapperResolver $resolver {
        get => $this->resolver ??= new SnapshotMapperResolver($this->store, $this->context);
    }

    public function __construct(private readonly PersistentStore $store, private readonly ManagedObjectContext $context)
    {
    }

    public function mapSnapshot(ManagedObject $object, Dictionary $snapshot): Dictionary
    {
        return $this->resolver->mapper->map($object, $snapshot);
    }
}
