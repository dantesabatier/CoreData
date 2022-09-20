<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 18:02
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Set;

/** @internal */
class RefreshRequest extends PersistentStoreRequest
{
    /** @var Set<ManagedObject> */
    public Set $refreshObjects;
    public RefreshRequestType $refreshType = RefreshRequestType::default;

    public function __construct()
    {
        parent::__construct();
        $this->refreshObjects = new Set();
    }
}
