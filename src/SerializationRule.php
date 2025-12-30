<?php

namespace Sabatier\CoreData;

/**
 * Defines the strategy for determining the default set of properties of a ManagedObject to include in `serializationKeys` when preparing the object for serialization.
 *
 * This enum controls:
 *   1. Which attributes and relationships are included by default when `serializationKeys` has not been explicitly set.
 *   2. How the object tree is traversed recursively.
 *   3. Prevention of infinite recursion by limiting the depth of traversal.
 */
enum SerializationRule: int
{
    /** Only attributes (non-transient) are included. */
    case attributesOnly = 0;
    /** Both attributes (non-transient) and relationships are included. Relationships are expanded recursively, stopping as needed to avoid infinite loops. */
    case attributesAndRelationships = 1;
    /** A custom set of properties is applied explicitly via a serialization shape (Dictionary) provided to `serialized()`. */
    case custom = 2;
}
