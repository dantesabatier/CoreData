<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

/**
 * Defines the contract for classes that can materialize elements.
 */
interface Materializable
{
    public function materialize(mixed $element): mixed;
}
