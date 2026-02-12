<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

interface StoreMetadataPruner
{
    public function prune(Dictionary $snapshot): void;
}
