<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\typeof;

final readonly class RelationshipMapper
{
    public function __construct(private ManagedObjectResolver $objectResolver)
    {
    }

    public function process(ManagedObject $contextObject, Dictionary $mappedValues, string $key, mixed $value, RelationshipDescription $relationship): void
    {
        $destinationEntity = $relationship->destinationEntity;
        if ($value instanceof ArrayClass || $value instanceof Set) {
            if ($relationship->isToMany) {
                $mappedValues[$key] = $value->compactMap(fn(ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject => $this->objectResolver->resolve($destinationEntity, $object));
            } elseif (!$value->isEmpty) {
                fatal_error(sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $contextObject->entity->name, typeof($value), $key));
            }
        } elseif ($value instanceof ManagedObject || $value instanceof ManagedObjectID || $value instanceof Dictionary) {
            $mappedValues[$key] = $this->objectResolver->resolve($destinationEntity, $value);
        } elseif ($value instanceof Nil) {
            $mappedValues[$key] = $value;
        } else {
            fatal_error(sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $contextObject->entity->name, typeof($value), $key));
        }
    }
}
