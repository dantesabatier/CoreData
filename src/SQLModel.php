<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/12/20
 * Time: 13:42
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/** @internal */
final class SQLModel extends StoreMapping
{
    /** @var Dictionary<SQLEntity> */
    public readonly Dictionary $entitiesByName;
    /** @var ArrayClass<SQLEntity> */
    public readonly ArrayClass $entities;

    public function __construct(public readonly ManagedObjectModel $managedObjectModel, public readonly string $configurationName)
    {
        $this->entitiesByName = $this->managedObjectModel->entitiesByName->mapValues(fn(EntityDescription $entityDescription): SQLEntity => new SQLEntity($this, $entityDescription));
        $this->entities = $this->entitiesByName->values;
        foreach ($this->entities as $entity) {
            $entity->generateInverseRelationshipsAndMore();
            $entity->doPostModelGenerationCleanup();
        }
    }

    public function entity(string $named): ?SQLEntity
    {
        /** @var SQLEntity|null $entity */
        $entity = $this->entitiesByName[$named];
        if (!$entity) {
            return null;
        }
        if ($entity->isRootEntity) {
            return $entity;
        }
        return $entity->rootEntity;
    }
}
