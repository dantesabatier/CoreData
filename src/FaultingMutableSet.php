<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\Set;

/**
 * @extends Set<mixed>
 * @internal
 */
class FaultingMutableSet extends Set
{
    public bool $isFault = true;

    public function __construct(public readonly ManagedObject $source, public readonly PropertyDescription $relationship)
    {
        parent::__construct();
    }

    public function turnIntoFault(): void
    {
        $this->isFault = true;
        $this->removeAll();
    }

    public function indexOf(mixed $element): ?int
    {
        if ($element instanceof ManagedObjectID) {
            $element = $this->source->managedObjectContext->object($element);
        }
        return parent::indexOf($element);
    }

    public function append(mixed $element): void
    {
        if ($element instanceof ManagedObject) {
            $element = $element->objectID;
        }
        parent::append($element);
    }

    public function insert(mixed $newElement): array
    {
        if ($newElement instanceof ManagedObject) {
            $newElement = $newElement->objectID;
        }
        return parent::insert($newElement);
    }

    public function insertAt(mixed $element, int $at): void
    {
        if ($element instanceof ManagedObject) {
            $element = $element->objectID;
        }
        parent::insertAt($element, $at);
    }

    public function remove(mixed $element): void
    {
        if ($element instanceof ManagedObject) {
            $element = $element->objectID;
        }
        parent::remove($element);
    }

    public function update(mixed $element)
    {
        if ($element instanceof ManagedObject) {
            $element = $element->objectID;
        }
        return parent::update($element);
    }

    public function containsElement(mixed $element): bool
    {
        if ($element instanceof ManagedObjectID) {
            $element = $this->source->managedObjectContext->object($element);
        }
        return parent::containsElement($element);
    }

    public function member(mixed $element)
    {
        if ($element instanceof ManagedObjectID) {
            $element = $this->source->managedObjectContext->object($element);
        }
        return parent::member($element);
    }

    public function setSet(Set $set): void
    {
        parent::setSet($set->map(fn(ManagedObject|ManagedObjectID $e): ManagedObjectID => $e instanceof ManagedObject ? $e->objectID : $e));
        $this->isFault = false;
    }

    public function current(): ManagedObject
    {
        $current = parent::current();
        if ($current instanceof ManagedObjectID) {
            return $this->source->managedObjectContext->object($current);
        }
        return $current;
    }

    public function offsetGet(mixed $offset): ManagedObject
    {
        $element = parent::offsetGet($offset);
        if ($element instanceof ManagedObjectID) {
            return $this->source->managedObjectContext->object($element);
        }
        return $element;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof ManagedObject) {
            $value = $value->objectID;
        }
        parent::offsetSet($offset, $value);
    }
}
