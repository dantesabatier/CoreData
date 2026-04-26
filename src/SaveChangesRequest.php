<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 05/06/20
 * Time: 02:58
 */
namespace Sabatier\CoreData;

use JetBrains\PhpStorm\Pure;
use Sabatier\Foundation\Set;

/**
 * An encapsulation of a collection of changes to be made by an object store in response to a save operation on a managed object context.
 */
final class SaveChangesRequest extends PersistentStoreRequest
{
    /**
     * Initializes a save changes request with collections of given changes.
     * @param Set<ManagedObject>|null $insertedObjects Objects that were inserted into the calling context.
     * @param Set<ManagedObject>|null $updatedObjects Objects that were updated in the calling context.
     * @param Set<ManagedObject>|null $deletedObjects Objects that were deleted in the calling context.
     * @param Set<ManagedObject>|null $lockedObjects Objects that were flagged for optimistic locking on the calling context.
     */
    #[Pure]
    public function __construct(public readonly ?Set $insertedObjects = null, public readonly ?Set $updatedObjects = null, public readonly ?Set $deletedObjects = null, public readonly ?Set $lockedObjects = null)
    {
        parent::__construct(PersistentStoreRequestType::saveRequestType);
    }
}
