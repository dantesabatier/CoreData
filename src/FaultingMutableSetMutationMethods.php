<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
trait FaultingMutableSetMutationMethods
{
    /** @var Dictionary<FaultingMutableSetMutationMethod>|null */
    public ?Dictionary $faultingMutableSetMutationMethods = null;

    /**
     * @param string $key
     * @return Dictionary<FaultingMutableSetMutationMethod>
     */
    public function createMutationMethods(string $key): Dictionary
    {
        if ($this->faultingMutableSetMutationMethods === null) {
            $this->faultingMutableSetMutationMethods = (new ArrayClass([FaultingMutableSetMutationMethod::addObjectMethod($this, $key), FaultingMutableSetMutationMethod::removeObjectMethod($this, $key), FaultingMutableSetMutationMethod::addMethod($this, $key), FaultingMutableSetMutationMethod::removeMethod($this, $key), FaultingMutableSetMutationMethod::intersectMethod($this, $key), FaultingMutableSetMutationMethod::setMethod($this, $key)]))->reduce(new Dictionary(), function(Dictionary $dictionary, FaultingMutableSetMutationMethod $method): Dictionary {
                $dictionary[$method->name] = $method;
                return $dictionary;
            });
        }
        return $this->faultingMutableSetMutationMethods;
    }
}
