<?php

namespace Sabatier\CoreData;

use Closure;
use Override;
use Sabatier\Foundation\Collection;
use function Sabatier\Foundation\compare;

/**
 * @psalm-require-implements Materializable
 * @psalm-require-implements Collection
 * @internal
 */
trait MaterializedSorting
{
    #[Override]
    public function sort(?Closure $by = null): self
    {
        if ($this->count <= 1) {
            return $this;
        }
        $by ??= fn(mixed $e0, mixed $e1): int => compare($e0, $e1);
        $this->recursiveMergeSort(0, $this->indexBefore($this->endIndex), $by);
        return $this;
    }

    /**
     * @param Closure(ManagedObject, ManagedObject): int $compare
     */
    private function recursiveMergeSort(int $left, int $right, Closure $compare): void
    {
        if ($left < $right) {
            $mid = (int)(($left + $right) / 2);
            $this->recursiveMergeSort($left, $mid, $compare);
            $this->recursiveMergeSort($mid + 1, $right, $compare);
            $this->merge($left, $mid, $right, $compare);
        }
    }

    /**
     * @param Closure(ManagedObject, ManagedObject): int $compare
     */
    private function merge(int $left, int $mid, int $right, Closure $compare): void
    {
        $n1 = $mid - $left + 1;
        $n2 = $right - $mid;
        $leftArray = [];
        $rightArray = [];
        for ($i = 0; $i < $n1; $i++) {
            $leftArray[$i] = $this->reserved[$left + $i];
        }
        for ($j = 0; $j < $n2; $j++) {
            $rightArray[$j] = $this->reserved[$mid + 1 + $j];
        }
        $i = 0;
        $j = 0;
        $k = $left;
        while ($i < $n1 && $j < $n2) {
            $objA = $this->materialize($leftArray[$i]);
            $objB = $this->materialize($rightArray[$j]);
            if ($compare($objA, $objB) <= 0) {
                $this->reserved[$k] = $leftArray[$i];
                $i++;
            } else {
                $this->reserved[$k] = $rightArray[$j];
                $j++;
            }
            $k++;
        }
        while ($i < $n1) {
            $this->reserved[$k] = $leftArray[$i];
            $i++;
            $k++;
        }
        while ($j < $n2) {
            $this->reserved[$k] = $rightArray[$j];
            $j++;
            $k++;
        }
    }
}
