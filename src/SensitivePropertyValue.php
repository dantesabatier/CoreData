<?php

namespace Sabatier\CoreData;

use JsonSerializable;
use Override;
use Sabatier\Foundation\CustomStringConvertible;
use function Sabatier\Foundation\class_name;
use function Sabatier\Foundation\typeof;

final readonly class SensitivePropertyValue implements CustomStringConvertible, JsonSerializable
{
    public function __construct(public mixed $value)
    {
    }

    #[Override]
    public function __toString(): string
    {
        return $this->description();
    }

    #[Override]
    public function description(): string
    {
        return sprintf("%s(%s)", typeof($this->value), class_name(SensitivePropertyValue::class));
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return $this->description();
    }
}
