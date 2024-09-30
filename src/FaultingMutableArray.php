<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;

/**
 * @extends ArrayClass<ManagedObject>
 * @internal
 */
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

    #[Override]
    public function setArray(ArrayClass $array): void
    {
        parent::setArray($array);
        $this->isFault = false;
    }
}
