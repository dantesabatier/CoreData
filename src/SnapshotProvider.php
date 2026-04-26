<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
interface SnapshotProvider
{
    /**
     * @param ManagedObject $object
     * @param ArrayClass<string> $properties
     * @return Dictionary<mixed>|null
     */
    public function snapshot(ManagedObject $object, ArrayClass $properties): ?Dictionary;

    /**
     * @param ManagedObject $object
     * @param ArrayClass<string> $properties
     * @param ArrayClass<ExpressionDescription> $expressions
     * @return Dictionary<mixed>|null
     */
    public function snapshotWithExpressions(ManagedObject $object, ArrayClass $properties, ArrayClass $expressions): ?Dictionary;
}
