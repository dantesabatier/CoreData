<?php

namespace Sabatier\CoreData;

/**
 * Constants that specify the reason the managed object may need to reinitialize its values.
 */
class SnapshotEventType
{
    /** @var int Specifies a change due to undo from insertion. */
    const undoInsertion = 1 << 1;
    /** @var int Specifies a change due to undo from deletion. */
    const undoDeletion = 1 << 2;
    /** @var int Specifies a change due to a property-level undo. */
    const undoUpdate = 1 << 3;
    /** @var int Specifies a change due to the managed object context being rolled back. */
    const rollback = 1 << 4;
    /** @var int Specifies a change due to the managed object being refreshed. */
    const refresh = 1 << 5;
    /** @var int Specifies a change due to conflict resolution during a save operation. */
    const mergePolicy = 1 << 6;
}
