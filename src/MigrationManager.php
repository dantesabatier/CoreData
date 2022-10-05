<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:41
 */

namespace Sabatier\CoreData;

use Exception;
use InvalidArgumentException;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Error;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;

use function Sabatier\Foundation\absolute_time_get_current;
use function Sabatier\Foundation\human_readable_time;
use function Sabatier\Foundation\typeof;

/**
 * Class MigrationManager
 * A migration manager instance that performs a migration of data from one persistent store to another using a given mapping model.
 * @package Sabatier\CoreData
 * @psalm-consistent-constructor
 * @property-read float $migrationProgress A number between 0 and 1 that indicates the proportion of completeness of the migration. If a migration is not taking place, this property is 1. You can observe this value using key-value observing.
 * @property-read EntityMapping|null $currentEntityMapping The entity mapping currently being processed.
 */
class MigrationManager extends ObjectClass
{
    /** @internal */
    public static int $migrationDebugLevel = 0;
    /** @var ManagedObjectContext The managed object context the migration manager uses for writing the destination persistent store. This context is created on demand as part of the initialization of the Core Data stacks used for migration. */
    public readonly ManagedObjectContext $destinationContext;
    /** @var MappingModel The mapping model for the migration manager. */
    public readonly MappingModel $mappingModel;
    /** @var ManagedObjectContext The managed object context the migration manager uses for reading the source persistent store. This context is created on demand as part of the initialization of the Core Data stacks used for migration. */
    public readonly ManagedObjectContext $sourceContext;
    /** @var Dictionary<mixed>|null The user info for the migration manager. */
    public ?Dictionary $userInfo = null;
    /** @var bool A Boolean value that indicates whether the migration manager tries to use a store specific migration manager to perform the migration. */
    public bool $usesStoreSpecificMigrationManager = true;
    protected float $migrationProgress = 1.0;
    private bool $migrationWasCancelled = false;
    /** @internal */
    public bool $performedInPlaceMigration = false;
    private ?Error $migrationCancellationError = null;
    private readonly MigrationContext $migrationContext;
    private EntityMigrationPolicy $entityMigrationPolicy;
    /** @var Dictionary<Dictionary<ArrayClass<ManagedObject>>> */
    private Dictionary $byMappingBySourceRelationshipsAssociationTable;
    private float $timestamp = 0.0;
    private bool $initialized = false;

    /**
     * Initializes a migration manager instance with given source and destination models.
     * @param ManagedObjectModel $sourceModel The source managed object model for the migration manager.
     * @param ManagedObjectModel $destinationModel The destination managed object model for the migration manager.
     */
    public function __construct(public readonly ManagedObjectModel $sourceModel, public readonly ManagedObjectModel $destinationModel)
    {
        $this->byMappingBySourceRelationshipsAssociationTable = new Dictionary();
        $this->migrationContext = new MigrationContext($this);
    }

    public function __get(string $name)
    {
        return match ($name) {
            'migrationProgress' => $this->$name,
            'currentEntityMapping' => $this->migrationContext->currentEntityMapping,
            default => $this->valueForUndefinedKey($name)
        };
    }

    /**
     * @throws Exception
     */
    public static function canMigrateWithMappingModel(/** @noinspection PhpUnusedParameterInspection */ MappingModel $mappingModel): bool
    {
        return true;
    }

    /**
     * @throws Exception
     */
    public static function performSanityCheck(/** @noinspection PhpUnusedParameterInspection */ MappingModel $mappingModel, ManagedObjectModel $sourceModel, ManagedObjectModel $destinationModel): bool
    {
        return true;
    }

    /**
     * @throws Exception
     */
    private function doFirstPassForMapping(EntityMapping $mapping): bool
    {
        $entityMigrationPolicyClass = EntityMigrationPolicy::class;
        if ($mapping->mappingType == EntityMappingType::customEntityMappingType) {
            $entityMigrationPolicyClass = $mapping->entityMigrationPolicyClassName ?? throw new InvalidArgumentException();
        }
        $this->entityMigrationPolicy = new $entityMigrationPolicyClass();
        if (!$this->entityMigrationPolicy->begin($mapping, $this)) {
            return false;
        }
        if (!($sourceEntityName = $mapping->sourceEntityName)) {
            return false;
        }
        $mappingType = $mapping->mappingType;
        if (
            $mappingType == EntityMappingType::addEntityMappingType ||
            $mappingType == EntityMappingType::removeEntityMappingType
        ) {
            return true;
        }
        $sourceEntity = $this->sourceEntity($mapping);
        $destinationEntity = $this->destinationEntity($mapping);
        if ($this->performedInPlaceMigration && ($mappingType == EntityMappingType::copyEntityMappingType || ($mappingType == EntityMappingType::transformEntityMappingType && $sourceEntity && $destinationEntity && $sourceEntity->isKindOf($destinationEntity)))) {
            return true;
        }
        $sourceContext = $this->sourceContext;
        /** @var FetchRequest<ManagedObject> $request */
        $request = new FetchRequest();
        $request->entity = EntityDescription::entity($sourceEntityName, $sourceContext);
        if ($mappingType == EntityMappingType::transformEntityMappingType) {
            $destinationAttributes = $destinationEntity?->attributesByName?->filter(fn(AttributeDescription $attribute): bool => !$attribute instanceof DerivedAttributeDescription);
            if ($destinationAttributes) {
                $sourceAttributes = $sourceEntity?->attributesByName?->filter(fn(AttributeDescription $attribute): bool => !$attribute instanceof DerivedAttributeDescription && $destinationAttributes->containsElement($attribute));
                if ($sourceAttributes) {
                    $destinationAttributes->merge($sourceAttributes);
                }
                $request->propertiesToFetch = $destinationAttributes->keys; // @phpstan-ignore-line
            }
        }
        $request->includesSubentities = false;
        $request->fetchBatchSize = 20;
        $instances = $sourceContext->fetch($request);
        $numberOfInstances = $instances->count();
        if ($numberOfInstances) {
            if (self::$migrationDebugLevel) {
                error_log("CoreData: Preparing $numberOfInstances instances");
            }
            $numberOfCreatedInstances = 0;
            foreach ($instances as $instance) {
                if (!$this->entityMigrationPolicy->createDestinationInstances($instance, $mapping, $this)) {
                    continue;
                }
                $numberOfCreatedInstances += 1;
            }
            if (self::$migrationDebugLevel) {
                error_log("CoreData: $numberOfCreatedInstances of $numberOfInstances instances created");
            }
        }
        return true;
    }

    /**
     * @throws Exception
     */
    private function doSecondPassForMapping(EntityMapping $mapping): bool
    {
        if (!($relationshipMappings = $mapping->relationshipMappings)) {
            return false;
        }
        $sources = $this->sourceInstances($mapping->name);
        foreach ($sources as $source) {
            foreach ($relationshipMappings as $relationshipMapping) {
                if (!($expression = $relationshipMapping->valueExpression)) {
                    continue;
                }
                $key = $relationshipMapping->name;
                $relationshipKey = (string)$source->objectID;
                /** @var Dictionary<ArrayClass<ManagedObject>> $relationshipsByName */
                $relationshipsByName = $this->byMappingBySourceRelationshipsAssociationTable[$key] ?? new Dictionary();
                /** @var ArrayClass<ManagedObject> $destinationInstances */
                $destinationInstances = $relationshipsByName[$relationshipKey] ?? new ArrayClass();
                $value = $source->valueForKey($key);
                if ($value instanceof Set) {
                    $destinationInstances->appendContentsOf($expression->expressionValue($source, new Dictionary(["\$manager" => $this, "\$source" => new ArrayClass($value->map(fn(ManagedObject|ManagedObjectID $e): ManagedObject => $e instanceof ManagedObject ? $e : $this->sourceContext->object($e)))])));
                } elseif ($value instanceof ManagedObject) {
                    $destinationInstances->appendContentsOf($expression->expressionValue($source, new Dictionary(["\$manager" => $this, "\$source" => new ArrayClass([$value])])));
                } elseif ($value) {
                    throw new InvalidArgumentException(sprintf('%s %s() Unexpected value "%s" for relationship %s->%s', $this->debugDescription(), __FUNCTION__, typeof($value), $source->entity->name, $key));
                }
                $relationshipsByName[$relationshipKey] = $destinationInstances;
                $this->byMappingBySourceRelationshipsAssociationTable[$key] = $relationshipsByName;
            }
        }
        $destinationInstances = $this->destinationInstances($mapping->name);
        foreach ($destinationInstances as $destinationInstance) {
            if ($this->entityMigrationPolicy->createRelationships($destinationInstance, $mapping, $this)) {
                /** @noinspection PhpExpressionResultUnusedInspection */
                $this->entityMigrationPolicy->endRelationshipCreation($mapping, $this);
            }
        }
        return true;
    }

    /**
     * @throws Exception
     */
    private function doThirdPassForMapping(EntityMapping $mapping): bool
    {
        if ($this->entityMigrationPolicy->performCustomValidation($mapping, $this)) {
            return $this->entityMigrationPolicy->end($mapping, $this);
        }
        return true;
    }

    /**
     * @throws Exception
     */
    private function do(int $pass, int $step, EntityMapping $mapping): void
    {
        $migrationContext = $this->migrationContext;
        $migrationContext->currentEntityMapping = $mapping;
        $migrationContext->currentMigrationStep = $step;
        $this->willChangeValueForKey('migrationProgress');
        $this->migrationProgress = $this->migrationContext->currentMigrationStep / ($this->mappingModel->entityMappings->count() * 3);
        $this->didChangeValueForKey('migrationProgress');
        if (!($destinationEntity = $this->destinationEntity($mapping)) || $destinationEntity->isAbstract) {
            return;
        }
        if (static::$migrationDebugLevel) {
            error_log(sprintf('CoreData: Processing entity mapping "%s" (pass %s of %s), elapsed time %s, %s%% completed', $mapping->name, $pass, 3, human_readable_time(absolute_time_get_current() - $this->timestamp), round($this->migrationProgress * 100, 2)));
        }
        if ($migrationCancellationError = $this->migrationCancellationError) {
            throw new Exception($migrationCancellationError->localizedDescription, $migrationCancellationError->code);
        }
        match ($pass) {
            1 => $this->doFirstPassForMapping($mapping),
            2 => $this->doSecondPassForMapping($mapping),
            3 => $this->doThirdPassForMapping($mapping),
            default => throw new InvalidArgumentException()
        };
    }

    /**
     * @throws Exception
     */
    protected function prepare(URL $sourceURL, PersistentStoreType $sourceType, ?Dictionary $sourceOptions, MappingModel $mappingModel, URL $destinationURL, PersistentStoreType $destinationType, ?Dictionary $destinationOptions): bool
    {
        if ($this->initialized) {
            return true;
        }
        if (!static::canMigrateWithMappingModel($mappingModel)) {
            return false;
        }
        if (!static::performSanityCheck($mappingModel, $this->sourceModel, $this->destinationModel)) {
            return false;
        }
        $this->initialized = true;
        $this->mappingModel = $mappingModel;
        $sourceOptions?->removeValueForKey(MigratePersistentStoresAutomaticallyOption);
        $destinationOptions?->removeValueForKey(MigratePersistentStoresAutomaticallyOption);
        $sourceCoordinator = new PersistentStoreCoordinator($this->sourceModel);
        $sourceCoordinator->addPersistentStoreWithType($sourceType, null, $sourceURL, $sourceOptions);
        $sourceContext = new ManagedObjectContext();
        $sourceContext->persistentStoreCoordinator = $sourceCoordinator;
        $this->sourceContext = $sourceContext;
        $destinationCoordinator = new PersistentStoreCoordinator($this->destinationModel);
        $destinationCoordinator->addPersistentStoreWithType($destinationType, null, $destinationURL, $destinationOptions);
        $destinationContext = new ManagedObjectContext();
        $destinationContext->persistentStoreCoordinator = $destinationCoordinator;
        $destinationContext->mergePolicy = MergePolicy::mergeByPropertyStoreTrump();
        $this->destinationContext = $destinationContext;
        $this->performedInPlaceMigration = $sourceType === $destinationType && $sourceURL->isEqual($destinationURL);
        return true;
    }

    /**
     * Migrates the store at a given source URL to the store at a given destination URL, performing all the mappings specified in a given mapping model.
     * This method performs compatibility checks on the source and destination models and the mapping model.
     * @param URL $sourceURL The location of an existing persistent store. A store must exist at this URL.
     * @param PersistentStoreType $sourceType The type of store at sourceURL (see {@see PersistentStoreCoordinator} for possible values).
     * @param Dictionary<mixed>|null $sourceOptions A dictionary of options for the source (see {@see PersistentStoreCoordinator} for possible values).
     * @param MappingModel $mappingModel The mapping model to use to effect the migration.
     * @param URL $destinationURL The location of the destination store.
     * @param PersistentStoreType $destinationType The type of store at dURL (see {@see PersistentStoreCoordinator} for possible values).
     * @param Dictionary<mixed>|null $destinationOptions A dictionary of options for the destination (see {@see PersistentStoreCoordinator} for possible values).
     * @return bool true if the migration proceeds without errors during the compatibility checks or migration, otherwise false.
     * @throws Exception If an error occurs during the validation or migration, upon return contains an error object that describes the problem.
     */
    public function migrateStore(URL $sourceURL, PersistentStoreType $sourceType, ?Dictionary $sourceOptions, MappingModel $mappingModel, URL $destinationURL, PersistentStoreType $destinationType, ?Dictionary $destinationOptions): bool
    {
        if (!$this->prepare($sourceURL, $sourceType, $sourceOptions, $mappingModel, $destinationURL, $destinationType, $destinationOptions)) {
            return false;
        }
        $this->timestamp = absolute_time_get_current();
        $mappings = $mappingModel->entityMappings;
        for ($i = 1; $i <= 3; $i++) {
            if ($this->migrationWasCancelled) {
                break;
            }
            foreach ($mappings as $index => $mapping) {
                $this->do($i, $i * $index, $mapping);
            }
        }
        $this->willChangeValueForKey('migrationProgress');
        $this->migrationProgress = 1.0;
        $this->didChangeValueForKey('migrationProgress');
        return true;
    }

    /**
     * Resets the association tables for the migration.
     * This method does not reset the source or destination contexts.
     */
    public function reset(): void
    {
        $this->migrationWasCancelled = false;
        $this->migrationCancellationError = null;
        $migrationContext = $this->migrationContext;
        $migrationContext->currentMigrationStep = 0;
        $migrationContext->currentEntityMapping = null;
        $migrationContext->currentPropertyMapping = null;
        $migrationContext->clearAssociationTables();
    }

    /**
     * Cancels the migration with a given error.
     * You can invoke this method from anywhere in the migration process to abort the migration.
     * Calling this method causes {@see migrateStore()} to abort the migration and return error you should provide an appropriate error to indicate the reason for the cancellation.
     * @param Error $error
     */
    public function cancelMigrationWithError(Error $error): void
    {
        $this->migrationWasCancelled = true;
        $this->migrationCancellationError = $error;
    }

    /**
     * Associates a given source managed object instance with an array of destination instances for a given property mapping.
     * Data migration is performed as a three-stage process (first create the data, then relate the data, then validate the data).
     * You use this method to associate data between the source and destination stores, in order to allow for relationship creation or fixup after the creation stage.
     * This method is called in the default implementation of {@see EntityMigrationPolicy::createDestinationInstances()} method.
     * @param ManagedObject $sourceInstance A source managed object.
     * @param ManagedObject $destinationInstance The destination managed object for sourceInstance.
     * @param EntityMapping $entityMapping The entity mapping to use to associate sourceInstance with the object in destinationInstances.
     */
    public function associate(ManagedObject $sourceInstance, ManagedObject $destinationInstance, EntityMapping $entityMapping): void
    {
        $this->migrationContext->associate($sourceInstance, $destinationInstance, $entityMapping);
    }

    /**
     * Returns the managed object instances created in the destination store for the named entity mapping for the given array of source instances.
     * @param string $mappingName The name of an entity mapping in use.
     * @param ArrayClass<ManagedObject>|null $sourceInstances An array of managed objects in the source store.
     * @return ArrayClass<ManagedObject> An array containing the managed object instances created in the destination store for the entity mapping named mappingName for sourceInstances.
     * If sourceInstances is nil, all the destination instances created by the specified property mapping are returned.
     */
    public function destinationInstances(string $mappingName, ?ArrayClass $sourceInstances = null): ArrayClass
    {
        if ($sourceInstances) {
            return $sourceInstances->flatMap(fn(ManagedObject $sourceInstance): ArrayClass => $this->migrationContext->destinationInstances(sourceInstance: $sourceInstance));
        }
        return $this->migrationContext->destinationInstances($this->mapping($mappingName));
    }

    /**
     * @param string $relationshipName
     * @param ArrayClass<ManagedObject> $sourceInstances
     * @return ArrayClass<ManagedObject>
     * @internal
     */
    public function destinationInstancesForSourceRelationshipNamed(string $relationshipName, ArrayClass $sourceInstances): ArrayClass
    {
        $relationshipsByName = $this->byMappingBySourceRelationshipsAssociationTable[$relationshipName];
        if ($relationshipsByName) {
            /** @psalm-suppress all */
            return $sourceInstances->flatMap(fn(ManagedObject $sourceInstance): iterable => $relationshipsByName[(string)$sourceInstance->objectID] ?? []);
        }
        return new ArrayClass();
    }

    /**
     * Returns the managed object instances in the source store used to create the given destination instances for the passed in property mapping.
     * @param string $mappingName The name of an entity mapping in use.
     * @param ArrayClass<ManagedObject>|null $destinationInstances An array of managed objects in the destination store.
     * @return ArrayClass<ManagedObject> An array containing the managed object instances in the source store used to create destinationInstances using the entity mapping named mappingName.
     * If destinationInstances is nil, all the source instances used to create the destination instance for this property mapping are returned.
     */
    public function sourceInstances(string $mappingName, ?ArrayClass $destinationInstances = null): ArrayClass
    {
        if ($destinationInstances) {
            return $destinationInstances->flatMap(fn(ManagedObject $destinationInstance): ArrayClass => $this->migrationContext->sourceInstances(destinationInstance: $destinationInstance));
        }
        return $this->migrationContext->sourceInstances($this->mapping($mappingName));
    }

    /**
     * Returns the entity description for the source entity of a given entity mapping.
     * Entity mappings do not store the actual description objects, but rather the name and version information of the entity.
     * @param EntityMapping $entityMapping An entity mapping.
     * @return EntityDescription|null The entity description for the source entity of entityMapping.
     */
    public function sourceEntity(EntityMapping $entityMapping): ?EntityDescription
    {
        if ($sourceEntityName = $entityMapping->sourceEntityName) {
            return $this->sourceModel->entitiesByName[$sourceEntityName];
        }
        return null;
    }

    /**
     * Returns the entity description for the destination entity of a given entity mapping
     * Entity mappings do not store the actual description objects, but rather the name and version information of the entity.
     * @param EntityMapping $entityMapping An entity mapping.
     * @return EntityDescription|null The entity description for the destination entity of $entityMapping.
     */
    public function destinationEntity(EntityMapping $entityMapping): ?EntityDescription
    {
        if ($destinationEntityName = $entityMapping->destinationEntityName) {
            return $this->destinationModel->entitiesByName[$destinationEntityName];
        }
        return null;
    }

    /** @psalm-suppress all */
    private function mapping(string $named): EntityMapping
    {
        return $this->mappingModel->entityMappingsByName[$named] ?? throw new InvalidArgumentException("entity mapping name \"$named\" does not exist");
    }
}
