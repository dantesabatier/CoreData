<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Set;

/** @internal */
trait FaultingMutableSetMutationMethods
{
    /** @var Set<FaultingMutableSetMutationMethod>|null */
    public ?Set $faultingMutableSetMutationMethods = null;

    public function createMutationMethods(string $key): ArrayClass
    {
        $faultingMutableSetMutationMethods = new ArrayClass([FaultingMutableSetMutationMethod::addObjectMethod($this, $key), FaultingMutableSetMutationMethod::removeObjectMethod($this, $key), FaultingMutableSetMutationMethod::addMethod($this, $key), FaultingMutableSetMutationMethod::removeMethod($this, $key), FaultingMutableSetMutationMethod::intersectMethod($this, $key), FaultingMutableSetMutationMethod::setMethod($this, $key)]);
        if ($this->faultingMutableSetMutationMethods === null) {
            $this->faultingMutableSetMutationMethods = new Set();
        }
        $this->faultingMutableSetMutationMethods->appendContentsOf($faultingMutableSetMutationMethods);
        return $faultingMutableSetMutationMethods;
    }
}
