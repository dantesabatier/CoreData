<?php

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\Equatable;

final readonly class GenerationToken implements Equatable
{
    public function __construct(public string $storeIdentifier, public int $origin, public int $generation)
    {
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof GenerationToken) {
            return $this->storeIdentifier === $other->storeIdentifier && $this->origin === $other->origin && $this->generation === $other->generation;
        }
        return false;
    }
}
