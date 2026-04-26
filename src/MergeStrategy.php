<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

/** @internal */
interface MergeStrategy
{
    /**
     * @param Dictionary<mixed> $cachedSnapshot
     * @param Dictionary<mixed> $persistedSnapshot
     * @return Dictionary<mixed>
     */
    public function merge(Dictionary $cachedSnapshot, Dictionary $persistedSnapshot): Dictionary;
}
