<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 10:42
 */

namespace Sabatier\CoreData;

/** @internal */
class SQLToMany extends SQLRelationship
{
    public SQLToOne $inverseToOne {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        get => $this->inverseRelationship;
    }
}
