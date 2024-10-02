<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectProtocol;

/**
 * @psalm-require-implements ObjectProtocol
 * @internal
 */
trait FaultingMutableSetMutationMethods
{
    /** @var Dictionary<FaultingMutableSetMutationMethod>|null */
    public ?Dictionary $faultingMutableSetMutationMethods = null;

    public function __call(string $name, array $arguments)
    {
        if ($method = $this->faultingMutableSetMutationMethods?->valueForKey($name)) {
            ($method->closure)(...$arguments);
            return;
        }
        $this->doesNotRecognizeSelector($name);
    }

    public function responds(string $selector): bool
    {
        if (parent::responds($selector)) {
            return true;
        }
        return (bool) $this->faultingMutableSetMutationMethods?->offsetExists($selector);
    }

    /**
     * @param string $key
     * @return Dictionary<FaultingMutableSetMutationMethod>
     */
    public function createMutationMethods(string $key): Dictionary
    {
        if ($this->faultingMutableSetMutationMethods === null) {
            $this->faultingMutableSetMutationMethods = new Dictionary();
        }
        $this->faultingMutableSetMutationMethods->merge((new ArrayClass([FaultingMutableSetMutationMethod::addObjectMethod($this, $key), FaultingMutableSetMutationMethod::removeObjectMethod($this, $key), FaultingMutableSetMutationMethod::addMethod($this, $key), FaultingMutableSetMutationMethod::removeMethod($this, $key), FaultingMutableSetMutationMethod::intersectMethod($this, $key), FaultingMutableSetMutationMethod::setMethod($this, $key)]))->reduce(new Dictionary(), function (Dictionary $dictionary, FaultingMutableSetMutationMethod $method): Dictionary {
            $dictionary[$method->name] = $method;
            return $dictionary;
        }));
        return $this->faultingMutableSetMutationMethods;
    }
}
