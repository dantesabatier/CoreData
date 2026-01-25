<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SnapshotMappingStrategyResolver
{
    /** @var Dictionary<SnapshotMappingStrategy> */
    private Dictionary $cache {
        get => $this->cache ??= new Dictionary();
    }
    public SnapshotMappingStrategy $strategy {
        get {
            $strategyClass = $this->store::snapshotMappingStrategyClass();
            $strategy = $this->cache[$strategyClass];
            if ($strategy) {
                return $strategy;
            }
            class_exists($strategyClass) && is_subclass_of($strategyClass, SnapshotMappingStrategy::class) ?: fatal_error("Class \"$strategyClass\" defined in $this->store is not a valid HydrationStrategy");
            $strategy = new $strategyClass($this->store, $this->context);
            $this->cache[$strategyClass] = $strategy;
            return $strategy;
        }
    }

    public function __construct(private readonly PersistentStore $store, private readonly ManagedObjectContext $context)
    {
    }
}
