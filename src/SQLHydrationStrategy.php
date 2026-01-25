<?php

namespace Sabatier\CoreData;

use Exception;
use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Predicates\ComparisonPredicate;
use Sabatier\Foundation\Predicates\Expression;

/** @internal */
readonly class SQLHydrationStrategy extends HydrationStrategy
{

    #[Override]
    protected function pruneStoreMetadata(Dictionary $snapshot): void
    {
        $snapshot->removeValueForKey("parentID");
    }

    /**
     * @throws Exception
     */
    #[Override]
    protected function resolveStoreSpecificAttributes(ManagedObject $object, Dictionary $representation, Dictionary $snapshot): void
    {
        $store = $this->store;
        assert($store instanceof SQLCore);
        /** @var SQLEntity $entity */
        $entity = $store->model->entitiesByName[$object->entity->name];
        foreach ($entity->foreignKeyColumns as $foreignKeyColumn) {
            $key = $foreignKeyColumn->columnName;
            if (!($value = $snapshot[$key])) {
                continue;
            }
            if (!$value instanceof Nil) {
                $debugDefault = SQLCore::$debugLevel;
                SQLCore::$debugLevel = SQLDebugLevel::none;
                $fetchRequest = new FetchRequest();
                $fetchRequest->entity = $foreignKeyColumn->toOneRelationship->destinationEntity->entityDescription;
                $fetchRequest->predicate = new ComparisonPredicate(Expression::expressionForKeyPath(SQLEntity::primaryKeyName), Expression::expressionForConstantValue((int)$value));
                $value = $this->context->fetch($fetchRequest)->first;
                SQLCore::$debugLevel = $debugDefault;
            }
            $representation[$foreignKeyColumn->toOneRelationship->name] = $value;
            $representation->removeValueForKey($key);
        }
    }
}
