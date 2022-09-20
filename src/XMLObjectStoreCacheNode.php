<?php

namespace Sabatier\CoreData;

use DOMElement;

/** @internal */
class XMLObjectStoreCacheNode extends AtomicStoreCacheNode
{
    public function __construct(public readonly DOMElement $data, ManagedObjectID $objectID)
    {
        parent::__construct($objectID);
    }
}