<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;

/** @internal */
class FaultingMutableArray extends ArrayClass
{
    public bool $isFault = true;

    public function __construct(public readonly ManagedObject $source, public readonly PropertyDescription $relationship)
    {
        parent::__construct();
    }

    public function turnIntoFault(): void
    {
        $this->isFault = true;
        $this->removeAll();
    }

    public function setArray(ArrayClass $array): void
    {
        parent::setArray($array);
        $this->isFault = false;
    }
}