<?php

namespace Sabatier\CoreData;

use Closure;
use Override;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\SetAlgebra;
use function Sabatier\Foundation\compare;

/**
 * @extends Set<ManagedObject>
 * @internal
 */
final class FaultingSet extends Set implements Materializable
{
    private(set) bool $isFault = true;

    public function __construct(public readonly ManagedObject $source, public readonly RelationshipDescription $relationship)
    {
        parent::__construct();
    }

    public function turnIntoFault(): void
    {
        $this->isFault = true;
        $this->removeAll();
    }

    #[Override]
    public function indexOf(mixed $element): ?int
    {
        if ($element instanceof ManagedObjectID) {
            $element = $this->source->managedObjectContext->object($element);
        }
        return parent::indexOf($element);
    }

    #[Override]
    public function insert(mixed $newElement): array
    {
        if ($newElement instanceof ManagedObject) {
            $newElement = $newElement->objectID;
        }
        return parent::insert($newElement);
    }

    #[Override]
    public function insertAt(mixed $element, int $at): void
    {
        if ($element instanceof ManagedObject) {
            $element = $element->objectID;
        }
        parent::insertAt($element, $at);
    }

    #[Override]
    public function remove(mixed $element): void
    {
        if ($element instanceof ManagedObject) {
            $element = $element->objectID;
        }
        parent::remove($element);
    }

    #[Override]
    public function update(mixed $element)
    {
        if ($element instanceof ManagedObject) {
            $element = $element->objectID;
        }
        return parent::update($element);
    }

    #[Override]
    public function containsElement(mixed $element): bool
    {
        if ($element instanceof ManagedObjectID) {
            $element = $this->source->managedObjectContext->object($element);
        }
        return parent::containsElement($element);
    }

    #[Override]
    public function member(mixed $element)
    {
        if ($element instanceof ManagedObjectID) {
            $element = $this->source->managedObjectContext->object($element);
        }
        return parent::member($element);
    }

    #[Override]
    public function setSet(Set $set): void
    {
        parent::setSet($set->map(fn(ManagedObject|ManagedObjectID $e): ManagedObjectID => $e instanceof ManagedObject ? $e->objectID : $e));
        $this->isFault = false;
    }

    #[Override]
    public function formUnion(iterable $other): void
    {
        parent::formUnion($other);
        $this->isFault = false;
    }

    #[Override]
    public function formIntersection(iterable $other): void
    {
        parent::formIntersection($other);
        $this->isFault = false;
    }

    public function formSymmetricDifference(SetAlgebra $other): void
    {
        parent::formSymmetricDifference($other);
        $this->isFault = false;
    }

    #[Override]
    public function subtract(iterable $other): void
    {
        parent::subtract($other);
        $this->isFault = false;
    }

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

    #[Override]
    public function materialize(mixed $element): ManagedObject
    {
        if ($element instanceof ManagedObjectID) {
            return $this->source->managedObjectContext->object($element);
        }
        return $element;
    }

    #[Override]
    public function current(): ManagedObject
    {
        return $this->materialize(parent::current());
    }

    #[Override]
    public function offsetGet(mixed $offset): ManagedObject
    {
        return $this->materialize(parent::offsetGet($offset));
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof ManagedObject) {
            $value = $value->objectID;
        }
        parent::offsetSet($offset, $value);
    }
}
