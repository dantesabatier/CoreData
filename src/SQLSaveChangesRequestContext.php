<?php

namespace Sabatier\CoreData;

use Exception;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\is_equal;

/** @internal */
final class SQLSaveChangesRequestContext extends SQLStoreRequestContext
{
    public SaveChangesRequest $request {
        get {
            /** @var SaveChangesRequest $request */
            $request = $this->persistentStoreRequest;
            return $request;
        }
    }
    #[Override]
    public bool $isWritingRequest {
        get => true;
    }

    public function __construct(SaveChangesRequest $request, ManagedObjectContext $context, SQLCore $sqlCore)
    {
        parent::__construct($request, $context, $sqlCore);
    }

    #[Override]
    protected function executeRequestCore(): bool
    {
        if (!($statement = $this->generator->statement)) {
            return false;
        }
        $this->connection->execute($statement);
        $this->resolveUpsertConflicts();
        $this->transactionID = new Number($this->connection->insertTransactionForRequestContext($this));
        return true;
    }

    /**
     * @throws Exception
     */
    private function resolveUpsertConflicts(): void
    {
        if (!($insertedObjects = $this->request->insertedObjects)) {
            return;
        }
        /** @var Dictionary<Set<ManagedObject>> $byEntity */
        $byEntity = $insertedObjects->reduce(new Dictionary(),
            /**
             * @param Dictionary<Set<ManagedObject>> $dictionary
             * @param ManagedObject $object
             * @return Dictionary<Set<ManagedObject>>
             */
            function (Dictionary $dictionary, ManagedObject $object): Dictionary {
                if ($object->entity->indexes->isEmpty) {
                    return $dictionary;
                }
                $dictionary[$object->entityName] ??= new Set();
                $dictionary[$object->entityName]?->insert($object);
                return $dictionary;
            });
        $context = $this->context;
        $sqlCore = $this->sqlCore;
        foreach ($byEntity as $entityName => $insertedObjects) {
            $entity = EntityDescription::entity($entityName, $context);
            foreach ($entity->indexes as $index) {
                if (!$index->isUnique) {
                    continue;
                }
                if (!($propertyName = $index->elements->first?->propertyName)) {
                    continue;
                }
                $insertedValues = $insertedObjects->map(fn(ManagedObject $object): mixed => $object->$propertyName);
                $fetchRequest = new FetchRequest();
                $fetchRequest->entity = $entity;
                $fetchRequest->predicate = Predicate::format("%K IN %@", new ArrayClass([$propertyName, $insertedValues]));
                /** @var ArrayClass<ManagedObject> $existingObjects */
                $existingObjects = $sqlCore->execute($fetchRequest, $context);
                foreach ($existingObjects as $existingObject) {
                    foreach ($insertedObjects as $insertedObject) {
                        if (is_equal($existingObject->$propertyName, $insertedObject->$propertyName) && !is_equal($insertedObject->objectID->referenceObject, $existingObject->objectID->referenceObject)) {
                            $insertedObject->objectID->referenceObject = $existingObject->objectID->referenceObject;
                        }
                    }
                }
            }
        }
    }
}
