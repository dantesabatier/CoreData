<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use function Sabatier\Foundation\substring_to_index;

/** @internal */
final readonly class ColumnDescriptor
{
    /** @var ArrayClass<string> */
    public ArrayClass $keyPathComponents;
    public string $keyPath;
    public string $basePathPrefix;
    public bool $hasParentPrefix;
    public string $parentPathPrefix;

    private function __construct(string $pattern)
    {
        $parts = explode("_", $pattern);
        if (count($parts) >= 3) {
            array_shift($parts);
        }
        $this->keyPathComponents = new ArrayClass($parts);
        $this->keyPath = implode(".", $parts);
        $lastUnderscorePos = strrpos($pattern, "_");
        $basePathPrefix = $lastUnderscorePos !== false ? substring_to_index($pattern, $lastUnderscorePos) : "";
        $this->basePathPrefix = $basePathPrefix;
        $secondLastUnderscorePos = strrpos($basePathPrefix, "_");
        $this->hasParentPrefix = $secondLastUnderscorePos !== false;
        $this->parentPathPrefix = $secondLastUnderscorePos !== false
            ? substring_to_index($basePathPrefix, $secondLastUnderscorePos)
            : "";
    }

    public static function forPattern(string $pattern): self
    {
        /** @var array<string, self> $cache */
        static $cache = [];
        return $cache[$pattern] ??= new self($pattern);
    }
}
