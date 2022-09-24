<?php
/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:40
 */

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;

/**
 * Class EntityMigrationPolicy
 * A policy instance that customizes the migration process for an entity mapping.
 * @package Sabatier\CoreData
 * @psalm-consistent-constructor
 */
class EntityMigrationPolicy extends ObjectClass
{
    /**
     * Sets up state information before the start of a given entity mapping.
     * This method is the precursor to the creation stage. In a custom class, you can implement this method to set up any state information that will be useful for the duration of the migration.
     * @param EntityMapping $mapping The mapping object in use.
     * @param MigrationManager $manager The migration manager performing the migration.
     * @return bool true if the method completes successfully, otherwise false.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function begin(/** @noinspection PhpUnusedParameterInspection */ EntityMapping $mapping, MigrationManager $manager): bool
    {
        return true;
    }

    /**
     * Creates the destination instance(s) for a given source instance.
     * This method is invoked by the migration manager on each source instance (as specified by the sourceExpression in the mapping) to create the corresponding destination instance(s).
     * It also associates the source and destination instances by calling {@see MigrationManager::associate()} method.
     * @param ManagedObject $sourceInstance The source instance for which to create destination instances.
     * @param EntityMapping $mapping The mapping object in use.
     * @param MigrationManager $manager The migration manager performing the migration.
     * @return bool true if the method completes successfully, otherwise false.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function createDestinationInstances(ManagedObject $sourceInstance, EntityMapping $mapping, MigrationManager $manager): bool
    {
        if ($destinationEntity = $manager->destinationEntity($mapping)) {
            $destinationContext = $manager->destinationContext;
            $managedObjectID = function () use ($destinationEntity, $sourceInstance, $destinationContext, $manager): ?ManagedObjectID {
                if ($manager->migrationWasInPlace && ($destinationStore = $destinationContext->persistentStoreCoordinator?->persistentStores->first())) {
                    $referenceObject = $sourceInstance->objectID->referenceObject;
                    if ($destinationStore instanceof AtomicStore) {
                        return $destinationStore->objectID($destinationEntity, $referenceObject);
                    } elseif ($destinationStore instanceof IncrementalStore) {
                        return $destinationStore->newObjectID($destinationEntity, $referenceObject);
                    }
                }
                return null;
            };
            $destinationInstance = ($objectID = $managedObjectID()) ? $destinationContext->object($objectID) : EntityDescription::insertNewObject($destinationEntity->name, $destinationContext);
            if ($attributeMappings = $mapping->attributeMappings) {
                foreach ($attributeMappings as $attributeMapping) {
                    if ($expression = $attributeMapping->valueExpression) {
                        $key = $attributeMapping->name;
                        $value = $expression->expressionValue($sourceInstance, new Dictionary(["\$manager" => $manager, "\$source" => $sourceInstance]));
                        $destinationInstance->setValueForKey($value, $key);
                    }
                }
            }
            $manager->associate($sourceInstance, $destinationInstance, $mapping);
            return true;
        }
        return false;
    }

    /**
     * Indicates the end of the instance creation stage for the specified entity mapping, and the precursor to the next migration stage.
     * You can override this method to clean up state from the creation of destination or to prepare state for the creation of relationships.
     * @param EntityMapping $mapping The mapping object in use.
     * @param MigrationManager $manager The migration manager performing the migration.
     * @return bool true if the method completes successfully, otherwise false.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function endInstanceCreation(/** @noinspection PhpUnusedParameterInspection */ EntityMapping $mapping, MigrationManager $manager): bool
    {
        return true;
    }

    /**
     * Constructs the relationships between the newly-created destination instances.
     * You can use this stage to (re)create relationships between migrated objects, you use the association lookup methods on the MigrationManager instance to determine the appropriate relationship targets.
     * @param ManagedObject $instance The destination instance for which to create relationships.
     * @param EntityMapping $mapping The mapping object in use.
     * @param MigrationManager $manager The migration manager performing the migration.
     * @return bool true if the method completes successfully, otherwise false.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function createRelationships(ManagedObject $instance, EntityMapping $mapping, MigrationManager $manager): bool
    {
        if ($relationshipMappings = $mapping->relationshipMappings) {
            foreach ($relationshipMappings as $relationshipMapping) {
                $key = $relationshipMapping->name;
                /** @var RelationshipDescription $relationship */
                $relationship = $instance->entity->relationshipsByName[$key];
                $sourceInstances = $manager->sourceInstances($mapping->name, new ArrayClass([$instance]));
                $destinationInstances = $manager->destinationInstancesForSourceRelationshipNamed($key, $sourceInstances);
                $value = $relationship->isToMany ? new Set($destinationInstances) : $destinationInstances->first();
                $instance->setValueForKey($value, $key);
            }
            return true;
        }
        return false;
    }

    /**
     * Indicates the end of the relationship creation stage for the specified entity mapping.
     * This method is invoked after {@see createRelationships()}; you can override it to clean up state from the creation of relationships, or prepare state for custom validation in {@see performCustomValidation()}.
     * @param EntityMapping $mapping The mapping object in use.
     * @param MigrationManager $manager The migration manager performing the migration.
     * @return bool true if the method completes successfully, otherwise false.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function endRelationshipCreation(/** @noinspection PhpUnusedParameterInspection */ EntityMapping $mapping, MigrationManager $manager): bool
    {
        return true;
    }

    /**
     * Provides the option to perform custom validation on migrated objects during the validation stage of the entity migration policy.
     * This method is called before the default save validation is performed by the framework.
     * If you implement this method, you must manually obtain the collection of objects you are interested in validating.
     * @param EntityMapping $mapping The mapping object in use.
     * @param MigrationManager $manager The migration manager performing the migration.
     * @return bool true if the method completes successfully, otherwise false.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function performCustomValidation(/** @noinspection PhpUnusedParameterInspection */ EntityMapping $mapping, MigrationManager $manager): bool
    {
        return true;
    }

    /**
     * Performs cleanup at the end of the migration, from any phase of the mapping.
     * This is the end to the given entity mapping.
     * You can implement this method to perform any clean-up at the end of the migration (from any of the three phases of the mapping).
     * @param EntityMapping $mapping The mapping object in use.
     * @param MigrationManager $manager The migration manager performing the migration.
     * @return bool true if the method completes successfully, otherwise false.
     * @throws Exception If an error occurs, upon return contains an error object that describes the problem.
     */
    public function end(/** @noinspection PhpUnusedParameterInspection */ EntityMapping $mapping, MigrationManager $manager): bool
    {
        return true;
    }
}
