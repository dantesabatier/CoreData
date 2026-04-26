<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
interface StoreMetadataPruner
{
    /**
     * @param Dictionary<mixed> $snapshot
     */
    public function prune(Dictionary $snapshot): void;
}
