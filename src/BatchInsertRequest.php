<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 20/06/20
 * Time: 21:43
 */

namespace Sabatier\CoreData;

use Closure;
use JetBrains\PhpStorm\Pure;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * A request to insert a batch of data in a persistent store.
 */
class BatchInsertRequest extends PersistentStoreRequest
{
    /** @var string The name of the managed entity to insert data into. */
    public string $entityName {
        get => $this->entity->name;
    }

    /**
     * @param EntityDescription $entity The managed entity to insert data into.
     * @param Closure(Dictionary): bool|null $dictionaryHandler A closure that provides a dictionary for your app to insert data into.
     * @param Closure(ManagedObject): bool|null $managedObjectHandler A closure that provides a managed object for your app to insert data into.
     * @param ArrayClass<Dictionary>|ArrayClass<ManagedObject>|null $objectsToInsert An array of dictionaries that represents the objects to insert with the keys as attribute names and their assigned values.
     * @param BatchInsertRequestResultType $resultType The type of result that Core Data returns from this request.
     */
    #[Pure]
    public function __construct(public EntityDescription $entity, public ?Closure $dictionaryHandler = null, public ?Closure $managedObjectHandler = null, public ?ArrayClass $objectsToInsert = null, public BatchInsertRequestResultType $resultType = BatchInsertRequestResultType::statusOnly)
    {
        parent::__construct(PersistentStoreRequestType::batchInsertRequestType);
    }
}
