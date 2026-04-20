<?php

namespace Sabatier\CoreData;

use function Sabatier\Foundation\unimplemented;

/**
 * A token that indicates which generation of the persistent store is being accessed.
 *
 * When a managed object context is pinned to a specific generation of the app data, a query generation token will be associated with that context.
 */
final readonly class QueryGenerationToken
{
    public function __construct(public string $storeIdentifier, public int $origin, public int $generation)
    {
    }

    public static function current(): self
    {
        unimplemented(self::class, __FUNCTION__);
    }

    public function isCompatible(?QueryGenerationToken $other): bool
    {
        if ($other instanceof QueryGenerationToken) {
            return $this->storeIdentifier === $other->storeIdentifier && $this->origin === $other->origin && $this->generation === $other->generation;
        }
        return false;
    }
}
