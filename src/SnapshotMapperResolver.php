<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class SnapshotMapperResolver
{
    /** @var Dictionary<SnapshotMapper> */
    private Dictionary $cache {
        get => $this->cache ??= new Dictionary();
    }

    public SnapshotMapper $mapper {
        get {
            $mapperClass = $this->store::snapshotMapperClass();
            $mapper = $this->cache[$mapperClass];
            if ($mapper) {
                return $mapper;
            }
            class_exists($mapperClass) && is_subclass_of($mapperClass, SnapshotMapper::class) ?: fatal_error("Class \"$mapperClass\" defined in $this->store is not a valid SnapshotMapper");
            $mapper = new $mapperClass($this->store, $this->context);
            $this->cache[$mapperClass] = $mapper;
            return $mapper;
        }
    }

    public function __construct(private readonly PersistentStore $store, private readonly ManagedObjectContext $context) {
    }
}
