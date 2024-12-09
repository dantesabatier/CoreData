<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 23/07/20
 * Time: 09:38
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\Predicates\Expression;

/**
 * A mapping instance that specifies how to map an entity from a source to a destination managed object model.
 */
class EntityMapping extends ObjectClass
{
    /** @var string|null The source entity name for the entity mapping. */
    public ?string $sourceEntityName = null;
    /** @var string|null The version hash of the source entity for the entity mapping. The version hash is calculated by Core Data based on the property values of the entity (see {@see NSEntityDescription::versionHash} method). The sourceEntityVersionHash must equal the version hash of the source entity represented by the mapping. */
    public ?string $sourceEntityVersionHash = null;
    /** @var Expression|null The source expression for the entity mapping. The source expression is used to obtain the collection of managed objects to process through the mapping. The expression can be a fetch request expression, or any other expression that evaluates to a collection. */
    public ?Expression $sourceExpression = null;
    /** @var string|null The destination entity name for the entity mapping. Mappings are not directly bound to entity descriptions. You can use the {@see MigrationManager::destinationEntity()} method to retrieve the entity description for this entity name. */
    public ?string $destinationEntityName = null;
    /** @var string|null The version hash for the destination entity for the entity mapping. The version hash is calculated by Core Data based on the property values of the entity (see {@see EntityDescription::versionHash} method). The destinationEntityVersionHash must equal the version hash of the destination entity represented by the mapping. */
    public ?string $destinationEntityVersionHash = null;
    /** @var string The name of the entity mapping. The name is used only as a means of distinguishing mappings in a model. If not specified, the value defaults to SOURCE->DESTINATION. */
    public string $name {
        get {
            if (!isset($this->name)) {
                $name = "";
                if ($sourceEntityName = $this->sourceEntityName) {
                    $name = $sourceEntityName;
                }
                if ($destinationEntityName = $this->destinationEntityName) {
                    $name .= "To$destinationEntityName";
                }
                $this->name = $name;
            }
            return $this->name;
        }
    }
    /** @var EntityMappingType The mapping type for the entity mapping. If you specify a custom entity mapping type, you must specify a value for the migration policy class name as well (see {@see entityMigrationPolicyClassName}). */
    public EntityMappingType $mappingType = EntityMappingType::undefinedEntityMappingType {
        set(EntityMappingType|int $value) {
            if (is_int($value)) {
                $value = EntityMappingType::from($value);
            }
            $this->mappingType = $value;
        }
    }
    /** @var class-string<EntityMigrationPolicy>|null $entityMigrationPolicyClassName The class name of the migration policy for the entity mapping. If not specified, the default migration class name is EntityMigrationPolicy. You can specify a subclass to provide custom behavior. */
    public ?string $entityMigrationPolicyClassName = null;
    /** @var ArrayClass<PropertyMapping>|null The array of attribute mappings for the entity mapping. The order of mappings in the array specifies the order in which the mappings will be processed during a migration. */
    public ?ArrayClass $attributeMappings = null;
    /** @var ArrayClass<PropertyMapping>|null The array of relationship mappings for the entity mapping. The order of mappings in the array specifies the order in which the mappings will be processed during a migration. */
    public ?ArrayClass $relationshipMappings = null;
    /** @var Dictionary|null The user info dictionary for the entity mapping. You can use the info dictionary in any way that might be useful in your migration. */
    public ?Dictionary $userInfo = null;
    public string $description {
        get => sprintf("<%s %s %s>", self::class, $this->name, $this->hash);
    }

    public function __construct(?string $name = null)
    {
        if ($name) {
            $this->name = $name;
        }
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof EntityMapping) {
            return $this->name === $other->name;
        }
        return parent::isEqual($other);
    }
}
