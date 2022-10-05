<?php

namespace Sabatier\CoreData;

/**
 * Constants that specify the reason the managed object may need to reinitialize its values.
 */
class SnapshotEventType
{
    /** @var int Specifies a change due to undo from insertion. */
    final const undoInsertion = 1 << 1;
    /** @var int Specifies a change due to undo from deletion. */
    final const undoDeletion = 1 << 2;
    /** @var int Specifies a change due to a property-level undo. */
    final const undoUpdate = 1 << 3;
    /** @var int Specifies a change due to the managed object context being rolled back. */
    final const rollback = 1 << 4;
    /** @var int Specifies a change due to the managed object being refreshed. */
    final const refresh = 1 << 5;
    /** @var int Specifies a change due to conflict resolution during a save operation. */
    final const mergePolicy = 1 << 6;
}
