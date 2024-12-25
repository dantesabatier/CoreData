<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:09
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ObjectClass;
use function Sabatier\Foundation\read_random;

/**
 * A token that indicates which generation of the persistent store is being accessed.
 *
 * When a managed object context is pinned to a specific generation of the app data, a query generation token will be associated with that context.
 */
class QueryGenerationToken extends ObjectClass
{
    private static ?QueryGenerationToken $current = null;
    private string $token {
        get => $this->token ??= base64_encode(read_random(16));
    }
    public string $description {
        get => $this->token;
    }

    public function __serialize(): array
    {
        return ["token" => $this->token];
    }

    public function __unserialize(array $data): void
    {
        $this->token = $data["token"];
    }

    /**
     * A token that informs a context to use the current generation.
     * @return QueryGenerationToken
     */
    public static function current(): QueryGenerationToken
    {
        self::$current ??= new QueryGenerationToken();
        return self::$current;
    }
}
