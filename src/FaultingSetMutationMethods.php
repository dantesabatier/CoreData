<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectProtocol;

/**
 * @phpstan-require-implements ObjectProtocol
 * @internal
 */
trait FaultingSetMutationMethods
{
    /** @var Dictionary<FaultingSetMutationMethod> */
    public Dictionary $faultingSetMutationMethods {
        get => $this->faultingSetMutationMethods ??= new Dictionary();
    }

    public function __call(string $name, array $arguments)
    {
        if ($method = $this->faultingSetMutationMethods->valueForKey($name)) {
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
        return $this->faultingSetMutationMethods->offsetExists($selector);
    }

    /**
     * @param string $key
     * @return Dictionary<FaultingSetMutationMethod>
     */
    public function createMutationMethods(string $key): Dictionary
    {
        $this->faultingSetMutationMethods->merge(new ArrayClass([FaultingSetMutationMethod::addObjectMethod($this, $key), FaultingSetMutationMethod::removeObjectMethod($this, $key), FaultingSetMutationMethod::addMethod($this, $key), FaultingSetMutationMethod::removeMethod($this, $key), FaultingSetMutationMethod::intersectMethod($this, $key), FaultingSetMutationMethod::setMethod($this, $key)])->reduce(new Dictionary(), function (Dictionary $dictionary, FaultingSetMutationMethod $method): Dictionary {
            $dictionary[$method->name] = $method;
            return $dictionary;
        }));
        return $this->faultingSetMutationMethods;
    }
}
