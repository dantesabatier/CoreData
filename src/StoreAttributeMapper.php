<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

interface StoreAttributeMapper
{
    public function map(ManagedObject $object, Dictionary $mappedValues, Dictionary $snapshot): void;
}
