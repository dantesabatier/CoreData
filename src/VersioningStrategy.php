<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;

interface VersioningStrategy
{
    /**
     * @param Dictionary<mixed> $baseline
     * @param Dictionary<mixed> $store
     * @return bool
     */
    public function hasConflict(Dictionary $baseline, Dictionary $store): bool;
}
