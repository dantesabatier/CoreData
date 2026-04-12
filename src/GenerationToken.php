<?php

namespace Sabatier\CoreData;

final readonly class GenerationToken
{
    public function __construct(public string $storeIdentifier, public int $origin, public int $generation)
    {
    }

    public function isCompatible(?GenerationToken $other): bool
    {
        if ($other instanceof GenerationToken) {
            return $this->storeIdentifier === $other->storeIdentifier && $this->origin <= $other->origin && $this->generation <= $other->generation;
        }
        return false;
    }
}
