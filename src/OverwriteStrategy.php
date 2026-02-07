<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class OverwriteStrategy implements MergeStrategy
{
    #[Override]
    public function merge(Dictionary $cachedSnapshot, Dictionary $persistedSnapshot): Dictionary
    {
        return $cachedSnapshot;
    }
}

