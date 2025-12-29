<?php

namespace Sabatier\CoreData;

/**
 * Constants that specify the reason the managed object may need to reinitialize its values.
 */
final class SnapshotEventType
{
    /** @var int Specifies a change due to undo from insertion. */
    final const int undoInsertion = 1 << 1;
    /** @var int Specifies a change due to undo from deletion. */
    final const int undoDeletion = 1 << 2;
    /** @var int Specifies a change due to a property-level undo. */
    final const int undoUpdate = 1 << 3;
    /** @var int Specifies a change due to the managed object context being rolled back. */
    final const int rollback = 1 << 4;
    /** @var int Specifies a change due to the managed object being refreshed. */
    final const int refresh = 1 << 5;
    /** @var int Specifies a change due to conflict resolution during a save operation. */
    final const int mergePolicy = 1 << 6;
}
