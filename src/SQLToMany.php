<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 10:42
 */
namespace Sabatier\CoreData;

/** @internal */
final class SQLToMany extends SQLRelationship
{
    public SQLToOne $inverseToOne {
        get {
            /** @var SQLToOne $inverseToOne */
            $inverseToOne = $this->inverseRelationship;
            return $inverseToOne;
        }
    }
}
