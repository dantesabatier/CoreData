<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class StoreTrumpStrategy implements MergeStrategy
{
    #[Override]
    public function merge(Dictionary $cachedSnapshot, Dictionary $persistedSnapshot): Dictionary
    {
        return $cachedSnapshot->merging($persistedSnapshot);
    }
}
