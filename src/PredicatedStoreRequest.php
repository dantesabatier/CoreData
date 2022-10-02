<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 12/08/20
 * Time: 05:43
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Predicate;

interface PredicatedStoreRequest
{
    public function entity(): EntityDescription;

    public function includesSubentities(): bool;

    public function predicate(): ?Predicate;
}
