<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:50
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;

/**
 * A bookmark for keeping track the most recent history that you've processed.
 * You can save a token to disk and fetch history when your app loads based on that token.
 */
class PersistentHistoryToken extends ObjectClass
{
    /**
     * @param Dictionary<Number> $storeTokens
     */
    function __construct(public readonly Dictionary $storeTokens)
    {
    }

    public function __serialize(): array
    {
        return $this->storeTokens->toArray();
    }

    public function __unserialize(array $data): void
    {
        $this->storeTokens = new Dictionary($data);
    }
}
