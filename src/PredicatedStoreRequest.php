<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 12/08/20
 * Time: 05:43
 */
namespace Sabatier\CoreData;

use Sabatier\Foundation\Predicates\Predicate;

interface PredicatedStoreRequest
{
    public EntityDescription $entity {
        get;
    }
    public bool $includesSubentities {
        get;
    }
    public ?Predicate $predicate {
        get;
    }
}
