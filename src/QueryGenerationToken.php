<?php

namespace Sabatier\CoreData;

final readonly class QueryGenerationToken
{
    public function __construct(public string $storeIdentifier, public int $origin, public int $generation)
    {
    }

    public function isCompatible(?QueryGenerationToken $other): bool
    {
        if ($other instanceof QueryGenerationToken) {
            return $this->storeIdentifier === $other->storeIdentifier && $this->origin === $other->origin && $this->generation === $other->generation;
        }
        return false;
    }
}
